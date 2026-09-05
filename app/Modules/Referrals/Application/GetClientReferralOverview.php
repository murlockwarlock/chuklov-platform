<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Domain\Enums\ReferralPayoutRequestStatus;
use App\Modules\Referrals\Domain\Enums\ReferralRewardLedgerEntryType;
use App\Modules\Referrals\Domain\Models\ReferralPayoutRequest;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use App\Modules\Referrals\Domain\Models\ReferralRewardLedgerEntry;
use App\Modules\Referrals\Domain\ValueObjects\ReferralRewardBalance;
use Carbon\CarbonImmutable;

final class GetClientReferralOverview
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly EnsureReferralIdentity $ensureIdentity,
        private readonly ReferralRewardBalanceProjection $balances,
    ) {}

    /** @return array<string, mixed> */
    public function handle(Client $client): array
    {
        $identity = $this->ensureIdentity->handle($client);
        $relationships = ReferralRelationship::query()
            ->where('organization_id', $this->context->id())
            ->where('referrer_client_id', $client->getKey())
            ->with(['referred:id,full_name'])
            ->withCount('commercialEvidence')
            ->withMax('commercialEvidence', 'observed_at')
            ->orderByDesc('registered_at')
            ->limit(50)
            ->get();
        $rewardBalances = $this->balances->forClient($client);
        $history = ReferralRewardLedgerEntry::query()
            ->where('organization_id', $this->context->id())
            ->where('beneficiary_client_id', $client->getKey())
            ->with('referred:id,full_name')
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get();
        $payouts = ReferralPayoutRequest::query()
            ->where('organization_id', $this->context->id())
            ->where('beneficiary_client_id', $client->getKey())
            ->orderByDesc('requested_at')
            ->limit(50)
            ->get();

        return [
            'link' => route('portal.referral', ['referralCode' => $identity->public_code]),
            'referredClientsCount' => ReferralRelationship::query()
                ->where('organization_id', $this->context->id())
                ->where('referrer_client_id', $client->getKey())
                ->count(),
            'registrations' => $relationships->map(static fn (ReferralRelationship $relationship): array => [
                'name' => $relationship->referred?->full_name ?: '—',
                'registeredAt' => $relationship->registered_at?->toIso8601String(),
                'financeEvidenceRecorded' => (int) ($relationship->commercial_evidence_count ?? 0) > 0,
                'financeEvidenceAt' => $relationship->commercial_evidence_max_observed_at === null
                    ? null
                    : CarbonImmutable::parse((string) $relationship->commercial_evidence_max_observed_at)->toIso8601String(),
            ])->values()->all(),
            'rewards' => [
                'balances' => array_map(static fn (ReferralRewardBalance $balance): array => [
                    'currency' => $balance->currency->value,
                    'accruedMinor' => $balance->accrued()->minorUnits(),
                    'availableMinor' => $balance->available()->minorUnits(),
                    'pendingPayoutMinor' => $balance->pending->minorUnits(),
                    'paidOutMinor' => $balance->paid->minorUnits(),
                ], $rewardBalances),
                'history' => $history->map(static function (ReferralRewardLedgerEntry $entry): array {
                    $type = ReferralRewardLedgerEntryType::from((string) $entry->getRawOriginal('entry_type'));
                    $currency = CurrencyCode::from((string) $entry->getRawOriginal('currency'));

                    return [
                        'typeLabel' => $type->label(),
                        'isReversal' => $type === ReferralRewardLedgerEntryType::Reversed,
                        'amountMinor' => $entry->amount_minor,
                        'currency' => $currency->value,
                        'clientName' => $entry->referred?->full_name,
                        'reason' => $entry->reason,
                        'occurredAt' => CarbonImmutable::parse((string) $entry->occurred_at)->toIso8601String(),
                    ];
                })->values()->all(),
                'payouts' => $payouts->map(static function (ReferralPayoutRequest $payout): array {
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
                })->values()->all(),
                'requestUrl' => route('portal.referrals.payouts.store'),
            ],
        ];
    }
}
