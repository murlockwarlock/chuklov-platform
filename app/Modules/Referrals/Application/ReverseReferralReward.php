<?php

namespace App\Modules\Referrals\Application;

use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Referrals\Domain\Enums\ReferralRewardLedgerEntryType;
use App\Modules\Referrals\Domain\Models\ReferralRewardLedgerEntry;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReverseReferralReward
{
    public function __construct(
        private readonly FinanceAuthorization $authorization,
        private readonly ReferralRewardBalanceProjection $balances,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, ReferralRewardLedgerEntry|int $entry, string $reason): ReferralRewardLedgerEntry
    {
        $organization = $this->authorization->authorizeManage($actor);
        $entryId = $entry instanceof ReferralRewardLedgerEntry ? (int) $entry->getKey() : $entry;

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите причину сторно.']);
        }

        return DB::transaction(function () use ($actor, $organization, $entryId, $reason): ReferralRewardLedgerEntry {
            $candidate = ReferralRewardLedgerEntry::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($entryId)
                ->firstOrFail();
            $beneficiary = Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($candidate->beneficiary_client_id)
                ->lockForUpdate()
                ->firstOrFail();
            $original = ReferralRewardLedgerEntry::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($candidate->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($original->getRawOriginal('entry_type') !== ReferralRewardLedgerEntryType::Earned->value) {
                throw ValidationException::withMessages(['entry' => 'Можно отменить только начисление.']);
            }

            $existing = ReferralRewardLedgerEntry::query()
                ->where('organization_id', $organization->getKey())
                ->where('reverses_entry_id', $original->getKey())
                ->where('entry_type', ReferralRewardLedgerEntryType::Reversed->value)
                ->first();

            if ($existing instanceof ReferralRewardLedgerEntry) {
                return $existing;
            }

            $currency = CurrencyCode::from((string) $original->getRawOriginal('currency'));
            $originalMoney = Money::ofMinor($original->amount_minor, $currency);
            $available = $this->balances->forCurrency($beneficiary, $currency)->available();

            if ($available->compareTo($originalMoney) < 0) {
                throw ValidationException::withMessages([
                    'entry' => 'Начисление уже зарезервировано или выплачено и не может быть отменено.',
                ]);
            }

            $reversal = new ReferralRewardLedgerEntry;
            $reversal->forceFill([
                'organization_id' => $organization->getKey(),
                'beneficiary_client_id' => $original->beneficiary_client_id,
                'referred_client_id' => $original->referred_client_id,
                'referral_relationship_id' => $original->referral_relationship_id,
                'referral_commercial_evidence_id' => $original->referral_commercial_evidence_id,
                'financial_obligation_id' => $original->financial_obligation_id,
                'financial_ledger_entry_id' => $original->financial_ledger_entry_id,
                'reward_program_version_id' => $original->reward_program_version_id,
                'entry_type' => ReferralRewardLedgerEntryType::Reversed->value,
                'amount_minor' => $original->amount_minor,
                'currency' => $currency->value,
                'reason_type' => 'manual_reversal',
                'reason' => trim($reason),
                'reverses_entry_id' => $original->getKey(),
                'idempotency_key' => 'referral.reward.reversed:'.$organization->getKey().':'.$original->getKey(),
                'request_hash' => hash('sha256', $original->getKey().'|'.trim($reason)),
                'occurred_at' => now(),
            ]);
            $reversal->save();
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'referral.reward.reversed',
                targetType: ReferralRewardLedgerEntry::class,
                targetId: (string) $reversal->getKey(),
                metadata: [
                    'beneficiary_client_id' => $beneficiary->getKey(),
                    'referred_client_id' => $original->referred_client_id,
                    'relationship_id' => $original->referral_relationship_id,
                    'evidence_id' => $original->referral_commercial_evidence_id,
                    'amount_minor' => $original->amount_minor,
                    'currency' => $currency->value,
                    'original_entry_id' => $original->getKey(),
                    'reason_present' => true,
                ],
            );

            return $reversal->refresh();
        });
    }
}
