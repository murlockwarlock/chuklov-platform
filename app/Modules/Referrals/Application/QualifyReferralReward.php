<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Finance\Application\FinancialReconciliationContract;
use App\Modules\Finance\Application\ReconcileFinancialObligation;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Domain\Enums\ReferralRewardLedgerEntryType;
use App\Modules\Referrals\Domain\Enums\ReferralRewardQualificationRule;
use App\Modules\Referrals\Domain\Models\ReferralCommercialEvidence;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use App\Modules\Referrals\Domain\Models\ReferralRewardLedgerEntry;
use App\Modules\Referrals\Domain\Models\ReferralRewardProgram;
use App\Modules\Referrals\Domain\Models\ReferralRewardProgramVersion;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;

final class QualifyReferralReward
{
    public function __construct(
        private readonly ReconcileFinancialObligation $reconciliation,
        private readonly FinancialReconciliationContract $contract,
        private readonly ReferralRewardCalculator $calculator,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(ReferralCommercialEvidence $evidence): ?ReferralRewardLedgerEntry
    {
        $organizationId = (int) $evidence->organization_id;

        return DB::transaction(function () use ($evidence, $organizationId): ?ReferralRewardLedgerEntry {
            if ($evidence->getRawOriginal('evidence_type') !== 'finance_obligation_settled'
                || $evidence->getRawOriginal('observation_source') !== 'finance') {
                return null;
            }

            $relationship = ReferralRelationship::query()
                ->where('organization_id', $organizationId)
                ->whereKey($evidence->referral_relationship_id)
                ->first();

            if (! $relationship instanceof ReferralRelationship
                || (int) $relationship->referred_client_id !== (int) $evidence->referred_client_id) {
                return null;
            }

            $beneficiary = Client::query()
                ->where('organization_id', $organizationId)
                ->whereKey($relationship->referrer_client_id)
                ->lockForUpdate()
                ->first();

            if (! $beneficiary instanceof Client) {
                return null;
            }

            $existing = ReferralRewardLedgerEntry::query()
                ->where('organization_id', $organizationId)
                ->where('referral_commercial_evidence_id', $evidence->getKey())
                ->where('entry_type', ReferralRewardLedgerEntryType::Earned->value)
                ->first();

            if ($existing instanceof ReferralRewardLedgerEntry) {
                return $existing;
            }

            $program = ReferralRewardProgram::query()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (! $program instanceof ReferralRewardProgram) {
                return null;
            }

            $version = ReferralRewardProgramVersion::query()
                ->where('organization_id', $organizationId)
                ->where('program_id', $program->getKey())
                ->where('effective_at', '<=', $evidence->observed_at)
                ->orderByDesc('effective_at')
                ->orderByDesc('version')
                ->first();

            if (! $version instanceof ReferralRewardProgramVersion || ! $version->enabled) {
                return null;
            }

            $qualificationRule = ReferralRewardQualificationRule::from($version->getRawOriginal('qualification_rule'));

            if ($qualificationRule === ReferralRewardQualificationRule::FirstSettledPayment
                && ReferralRewardLedgerEntry::query()
                    ->where('organization_id', $organizationId)
                    ->where('referral_relationship_id', $relationship->getKey())
                    ->where('entry_type', ReferralRewardLedgerEntryType::Earned->value)
                    ->exists()) {
                return null;
            }

            $obligation = FinancialObligation::query()
                ->where('organization_id', $organizationId)
                ->whereKey($evidence->financial_obligation_id)
                ->first();
            $ledgerEntry = FinancialLedgerEntry::query()
                ->where('organization_id', $organizationId)
                ->whereKey($evidence->financial_ledger_entry_id)
                ->first();

            if (! $obligation instanceof FinancialObligation
                || ! $ledgerEntry instanceof FinancialLedgerEntry
                || (int) $obligation->client_id !== (int) $relationship->referred_client_id
                || (int) $ledgerEntry->obligation_id !== (int) $obligation->getKey()) {
                return null;
            }

            $settlement = $this->settlement($obligation, $ledgerEntry, $organizationId);
            $reward = $this->calculator->calculate($version, $settlement);

            if (! $reward->isPositive()) {
                return null;
            }

            $organization = Organization::query()->findOrFail($organizationId);
            $idempotencyKey = 'referral.reward.earned:'.$organizationId.':'.$evidence->getKey().':'.$version->getKey();
            $entry = new ReferralRewardLedgerEntry;
            $entry->forceFill([
                'organization_id' => $organizationId,
                'beneficiary_client_id' => $beneficiary->getKey(),
                'referred_client_id' => $relationship->referred_client_id,
                'referral_relationship_id' => $relationship->getKey(),
                'referral_commercial_evidence_id' => $evidence->getKey(),
                'financial_obligation_id' => $obligation->getKey(),
                'financial_ledger_entry_id' => $ledgerEntry->getKey(),
                'reward_program_version_id' => $version->getKey(),
                'entry_type' => ReferralRewardLedgerEntryType::Earned->value,
                'amount_minor' => $reward->minorUnits(),
                'currency' => $reward->currency()->value,
                'reason_type' => 'finance_settlement',
                'reason' => null,
                'reverses_entry_id' => null,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => hash('sha256', json_encode([
                    'organization_id' => $organizationId,
                    'evidence_id' => $evidence->getKey(),
                    'program_version_id' => $version->getKey(),
                    'amount_minor' => $reward->minorUnits(),
                    'currency' => $reward->currency()->value,
                ], JSON_THROW_ON_ERROR)),
                'occurred_at' => $evidence->observed_at,
            ]);
            $entry->save();
            $this->audit->handle(
                organization: $organization,
                actor: null,
                action: 'referral.reward.earned',
                targetType: ReferralRewardLedgerEntry::class,
                targetId: (string) $entry->getKey(),
                metadata: [
                    'beneficiary_client_id' => $beneficiary->getKey(),
                    'referred_client_id' => $relationship->referred_client_id,
                    'relationship_id' => $relationship->getKey(),
                    'evidence_id' => $evidence->getKey(),
                    'program_version_id' => $version->getKey(),
                    'amount_minor' => $reward->minorUnits(),
                    'currency' => $reward->currency()->value,
                ],
            );

            return $entry->refresh();
        });
    }

    private function settlement(
        FinancialObligation $obligation,
        FinancialLedgerEntry $ledgerEntry,
        int $organizationId,
    ): Money {
        $reconciliation = $this->reconciliation->handle($organizationId, (int) $obligation->getKey(), true);

        if (! $reconciliation->isSettled()) {
            return Money::zero($obligation->settlement_currency);
        }

        $obligationData = $this->contract->validateObligation($obligation);
        $ledgerData = $this->contract->validateLedgerForReconciliation($ledgerEntry);

        if ((int) $ledgerEntry->obligation_id !== (int) $obligation->getKey()
            || $ledgerData['currencies']['settlement_currency'] !== $obligationData['currencies']['settlement_currency']) {
            return Money::zero($obligationData['currencies']['settlement_currency']);
        }

        return Money::ofMinor(
            $obligationData['amounts']['settlement_amount_minor'],
            $obligationData['currencies']['settlement_currency'],
        );
    }
}
