<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Domain\Enums\ReferralCampaignChannel;
use App\Modules\Referrals\Domain\Enums\ReferralEstablishmentMethod;
use App\Modules\Referrals\Domain\Enums\ReferralPartnerStatus;
use App\Modules\Referrals\Domain\Enums\ReferralPayoutRequestStatus;
use App\Modules\Referrals\Domain\Enums\ReferralRewardLedgerEntryType;
use App\Modules\Referrals\Domain\Models\ReferralCampaignLink;
use App\Modules\Referrals\Domain\Models\ReferralLinkVisit;
use App\Modules\Referrals\Domain\Models\ReferralPartnerProfile;
use App\Modules\Referrals\Domain\Models\ReferralPayoutRequest;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use App\Modules\Referrals\Domain\Models\ReferralRewardLedgerEntry;
use App\Modules\Referrals\Domain\ValueObjects\ReferralRewardBalance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class GetReferralPartnerOverview
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly EnsureReferralIdentity $ensureIdentity,
        private readonly ReferralRewardBalanceProjection $balances,
        private readonly BuildReferralTelegramUrl $telegramUrl,
    ) {}

    /** @return array<string, mixed> */
    public function handle(Client $client): array
    {
        $organizationId = $this->context->id();
        abort_unless((int) $client->organization_id === $organizationId, 404);
        $identity = $this->ensureIdentity->handle($client);
        $profile = ReferralPartnerProfile::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $client->getKey())
            ->with(['campaignLinks' => fn ($query) => $query->orderBy('created_at')->orderBy('id')])
            ->first();
        $links = $profile instanceof ReferralPartnerProfile ? $profile->campaignLinks : collect();
        $linkIds = $links->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
        $relationshipQuery = ReferralRelationship::query()
            ->where('organization_id', $organizationId)
            ->where('referrer_client_id', $client->getKey());
        $relationships = (clone $relationshipQuery)
            ->with([
                'referred:id,full_name',
                'referralCampaignLink:id,name,channel,partner_client_id',
            ])
            ->withCount('commercialEvidence')
            ->withMax('commercialEvidence', 'observed_at')
            ->latest('registered_at')
            ->limit(50)
            ->get();
        $visitsByLink = $this->visitsByLink($organizationId, $linkIds);
        $registrationsByLink = $this->registrationsByLink($organizationId, $client->getKey(), $linkIds);
        $paidClientsByLink = $this->paidClientsByLink($organizationId, $client->getKey(), $linkIds);
        $rewardsByLink = $this->rewardsByLink($organizationId, $client->getKey());
        $rewardBalances = $this->balances->forClient($client);
        $history = ReferralRewardLedgerEntry::query()
            ->where('organization_id', $organizationId)
            ->where('beneficiary_client_id', $client->getKey())
            ->with('referred:id,full_name')
            ->latest('occurred_at')
            ->limit(50)
            ->get();
        $payouts = ReferralPayoutRequest::query()
            ->where('organization_id', $organizationId)
            ->where('beneficiary_client_id', $client->getKey())
            ->latest('requested_at')
            ->limit(50)
            ->get();
        $registrationCount = (clone $relationshipQuery)->count();
        $paidClientCount = (clone $relationshipQuery)
            ->whereHas('commercialEvidence')
            ->select('referred_client_id')
            ->distinct()
            ->count();
        $visitCount = $linkIds === []
            ? 0
            : ReferralLinkVisit::query()
                ->where('organization_id', $organizationId)
                ->whereIn('campaign_link_id', $linkIds)
                ->count();
        $trackedRegistrationCount = $linkIds === []
            ? 0
            : (clone $relationshipQuery)->whereIn('referral_campaign_link_id', $linkIds)->count();

        return [
            'isPartner' => $profile?->isActive() === true,
            'status' => $profile === null
                ? null
                : ReferralPartnerStatus::tryFrom((string) $profile->getRawOriginal('status'))?->value,
            'activatedAt' => $profile === null || $profile->getRawOriginal('activated_at') === null
                ? null
                : CarbonImmutable::parse((string) $profile->getRawOriginal('activated_at'))->toIso8601String(),
            'link' => $this->telegramUrl->handle($identity->public_code),
            'activationUrl' => route('portal.referrals.activate'),
            'createLinkUrl' => route('portal.referrals.links.store'),
            'referredClientsCount' => $registrationCount,
            'stats' => [
                'visits' => $visitCount,
                'registrations' => $registrationCount,
                'paidClients' => $paidClientCount,
                'visitToRegistrationRate' => $this->conversion($trackedRegistrationCount, $visitCount),
                'registrationToPaidClientRate' => $this->conversion($paidClientCount, $registrationCount),
                'rewardEarned' => $this->moneyList($rewardBalances),
            ],
            'links' => $links->map(fn (ReferralCampaignLink $link): array => [
                'name' => $link->name,
                'channel' => ReferralCampaignChannel::tryFrom((string) $link->getRawOriginal('channel'))?->label() ?? 'Другое',
                'isActive' => $link->is_active,
                'createdAt' => $link->getRawOriginal('created_at') === null
                    ? null
                    : CarbonImmutable::parse((string) $link->getRawOriginal('created_at'))->toIso8601String(),
                'shareUrl' => $this->telegramUrl->handle($link->public_token),
                'disableUrl' => route('portal.referrals.links.disable', ['campaignLinkId' => $link->getKey()]),
                'visits' => (int) ($visitsByLink[(int) $link->getKey()] ?? 0),
                'registrations' => (int) ($registrationsByLink[(int) $link->getKey()] ?? 0),
                'paidClients' => (int) ($paidClientsByLink[(int) $link->getKey()] ?? 0),
                'rewards' => $this->linkRewards($rewardsByLink[(int) $link->getKey()] ?? []),
            ])->values()->all(),
            'registrations' => $relationships->map(fn (ReferralRelationship $relationship): array => $this->registration($relationship))->values()->all(),
            'referredClients' => $relationships->map(fn (ReferralRelationship $relationship): array => $this->registration($relationship))->values()->all(),
            'rewards' => [
                'balances' => array_map(fn (ReferralRewardBalance $balance): array => [
                    'currency' => $balance->currency->value,
                    'earnedMinor' => $balance->earned->minorUnits(),
                    'accruedMinor' => $balance->accrued()->minorUnits(),
                    'availableMinor' => $balance->available()->minorUnits(),
                    'pendingPayoutMinor' => $balance->pending->minorUnits(),
                    'paidOutMinor' => $balance->paid->minorUnits(),
                ], $rewardBalances),
                'history' => $history->map(fn (ReferralRewardLedgerEntry $entry): array => $this->rewardHistory($entry))->values()->all(),
                'payouts' => $payouts->map(fn (ReferralPayoutRequest $payout): array => $this->payout($payout))->values()->all(),
                'requestUrl' => route('portal.referrals.payouts.store'),
            ],
        ];
    }

    /**
     * @param  array<int, int>  $linkIds
     * @return Collection<int|string, int>
     */
    private function visitsByLink(int $organizationId, array $linkIds): Collection
    {
        if ($linkIds === []) {
            return collect();
        }

        return ReferralLinkVisit::query()
            ->where('organization_id', $organizationId)
            ->whereIn('campaign_link_id', $linkIds)
            ->select('campaign_link_id')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('campaign_link_id')
            ->pluck('aggregate', 'campaign_link_id')
            ->map(static fn (mixed $value): int => (int) $value);
    }

    /**
     * @param  array<int, int>  $linkIds
     * @return Collection<int|string, int>
     */
    private function registrationsByLink(int $organizationId, int $clientId, array $linkIds): Collection
    {
        if ($linkIds === []) {
            return collect();
        }

        return ReferralRelationship::query()
            ->where('organization_id', $organizationId)
            ->where('referrer_client_id', $clientId)
            ->whereIn('referral_campaign_link_id', $linkIds)
            ->select('referral_campaign_link_id')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('referral_campaign_link_id')
            ->pluck('aggregate', 'referral_campaign_link_id')
            ->map(static fn (mixed $value): int => (int) $value);
    }

    /**
     * @param  array<int, int>  $linkIds
     * @return Collection<int|string, int>
     */
    private function paidClientsByLink(int $organizationId, int $clientId, array $linkIds): Collection
    {
        if ($linkIds === []) {
            return collect();
        }

        return ReferralRelationship::query()
            ->where('organization_id', $organizationId)
            ->where('referrer_client_id', $clientId)
            ->whereIn('referral_campaign_link_id', $linkIds)
            ->whereHas('commercialEvidence')
            ->select('referral_campaign_link_id')
            ->selectRaw('COUNT(DISTINCT referred_client_id) AS aggregate')
            ->groupBy('referral_campaign_link_id')
            ->pluck('aggregate', 'referral_campaign_link_id')
            ->map(static fn (mixed $value): int => (int) $value);
    }

    /** @return array<int, array<string, array{amountMinor: int|string, currency: string}>> */
    private function rewardsByLink(int $organizationId, int $clientId): array
    {
        $ledgerTable = (new ReferralRewardLedgerEntry)->getTable();
        $relationshipTable = (new ReferralRelationship)->getTable();
        $rows = ReferralRewardLedgerEntry::query()
            ->join($relationshipTable, $relationshipTable.'.id', '=', $ledgerTable.'.referral_relationship_id')
            ->where($ledgerTable.'.organization_id', $organizationId)
            ->where($ledgerTable.'.beneficiary_client_id', $clientId)
            ->whereIn($ledgerTable.'.entry_type', [
                ReferralRewardLedgerEntryType::Earned->value,
                ReferralRewardLedgerEntryType::Reversed->value,
            ])
            ->whereNotNull($relationshipTable.'.referral_campaign_link_id')
            ->select($relationshipTable.'.referral_campaign_link_id AS link_id', $ledgerTable.'.currency')
            ->selectRaw(
                'SUM(CASE WHEN referral_reward_ledger_entries.entry_type = ? THEN referral_reward_ledger_entries.amount_minor ELSE -referral_reward_ledger_entries.amount_minor END) AS amount_minor',
                [ReferralRewardLedgerEntryType::Earned->value],
            )
            ->groupBy($relationshipTable.'.referral_campaign_link_id', $ledgerTable.'.currency')
            ->get();
        $rewards = [];

        foreach ($rows as $row) {
            $linkId = (int) $row->getAttribute('link_id');
            $currency = CurrencyCode::from((string) $row->getRawOriginal('currency'))->value;
            $rewards[$linkId][$currency] = [
                'amountMinor' => (string) $row->getAttribute('amount_minor'),
                'currency' => $currency,
            ];
        }

        return $rewards;
    }

    /** @param array<string, array{amountMinor: int|string, currency: string}> $rewards
     * @return list<array{amountMinor: int|string, currency: string}>
     */
    private function linkRewards(array $rewards): array
    {
        return array_values($rewards);
    }

    /** @param list<ReferralRewardBalance> $balances
     * @return list<array{currency: string, amountMinor: int}>
     */
    private function moneyList(array $balances): array
    {
        return array_map(
            fn (ReferralRewardBalance $balance): array => [
                'currency' => $balance->currency->value,
                'amountMinor' => $balance->accrued()->minorUnits(),
            ],
            $balances,
        );
    }

    /** @return array<string, mixed> */
    private function registration(ReferralRelationship $relationship): array
    {
        $link = $relationship->referralCampaignLink;
        $paidClient = (int) ($relationship->commercial_evidence_count ?? 0) > 0;

        return [
            'name' => $relationship->referred?->full_name ?: '—',
            'registeredAt' => $relationship->registered_at?->toIso8601String(),
            'financeEvidenceRecorded' => $paidClient,
            'financeEvidenceAt' => $relationship->commercial_evidence_max_observed_at === null
                ? null
                : CarbonImmutable::parse((string) $relationship->commercial_evidence_max_observed_at)->toIso8601String(),
            'paidClient' => $paidClient,
            'linkName' => $link instanceof ReferralCampaignLink
                ? $link->name
                : ($relationship->establishment_method === ReferralEstablishmentMethod::ManualCrm
                    ? 'Назначено в CRM'
                    : 'Персональная ссылка'),
            'channel' => $link instanceof ReferralCampaignLink
                ? (ReferralCampaignChannel::tryFrom((string) $link->getRawOriginal('channel'))?->label() ?? 'Другое')
                : ($relationship->establishment_method === ReferralEstablishmentMethod::ManualCrm ? 'CRM' : 'Ссылка'),
        ];
    }

    /** @return array<string, mixed> */
    private function rewardHistory(ReferralRewardLedgerEntry $entry): array
    {
        $type = ReferralRewardLedgerEntryType::from((string) $entry->getRawOriginal('entry_type'));
        $currency = CurrencyCode::from((string) $entry->getRawOriginal('currency'));

        return [
            'typeLabel' => $type->label(),
            'isReversal' => $type === ReferralRewardLedgerEntryType::Reversed,
            'amountMinor' => $entry->amount_minor,
            'currency' => $currency->value,
            'clientName' => $entry->referred?->full_name,
            'reason' => $entry->reason,
            'comment' => $entry->comment,
            'occurredAt' => CarbonImmutable::parse((string) $entry->occurred_at)->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function payout(ReferralPayoutRequest $payout): array
    {
        $currency = CurrencyCode::from((string) $payout->getRawOriginal('currency'));
        $status = ReferralPayoutRequestStatus::from((string) $payout->getRawOriginal('status'));

        return [
            'amountMinor' => $payout->amount_minor,
            'currency' => $currency->value,
            'requestedAt' => CarbonImmutable::parse((string) $payout->requested_at)->toIso8601String(),
            'statusLabel' => $status->label(),
            'rejectionReason' => $payout->rejection_reason,
            'canCancel' => $status === ReferralPayoutRequestStatus::Requested,
            'cancelUrl' => route('portal.referrals.payouts.cancel', ['payoutRequestId' => $payout->getKey()]),
        ];
    }

    private function conversion(int $numerator, int $denominator): ?float
    {
        return $denominator === 0 ? null : round(($numerator / $denominator) * 100, 1);
    }
}
