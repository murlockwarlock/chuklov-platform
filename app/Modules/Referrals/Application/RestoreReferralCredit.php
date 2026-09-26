<?php

namespace App\Modules\Referrals\Application;

use App\Models\User;
use App\Modules\Finance\Application\AppendFinancialLedgerEntry;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\FinancialReconciliationContract;
use App\Modules\Finance\Application\ReconcileFinancialObligation;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialEntrySource;
use App\Modules\Finance\Domain\Enums\FinancialLedgerEntryType;
use App\Modules\Finance\Domain\Models\FinanceIdempotencyKey;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\ValueObjects\FinancialLedgerEntryData;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Referrals\Domain\Enums\ReferralRewardCategory;
use App\Modules\Referrals\Domain\Enums\ReferralRewardLedgerEntryType;
use App\Modules\Referrals\Domain\Models\ReferralRewardLedgerEntry;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RestoreReferralCredit
{
    public function __construct(
        private readonly FinanceAuthorization $authorization,
        private readonly FinancialReconciliationContract $contract,
        private readonly AppendFinancialLedgerEntry $ledger,
        private readonly ReconcileFinancialObligation $reconciliation,
        private readonly ReferralRewardBalanceProjection $balances,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function canHandle(FinancialLedgerEntry $entry): bool
    {
        return $entry->getRawOriginal('entry_type') === FinancialLedgerEntryType::ReferralCredit->value
            && $entry->getRawOriginal('source') === FinancialEntrySource::Referral->value
            && $entry->getRawOriginal('payment_method') === 'referral_credit'
            && ReferralRewardLedgerEntry::query()
                ->where('organization_id', $entry->getRawOriginal('organization_id'))
                ->where('financial_ledger_entry_id', $entry->getKey())
                ->where('entry_type', ReferralRewardLedgerEntryType::Redeemed->value)
                ->where('reward_category', ReferralRewardCategory::ServiceCredit->value)
                ->exists();
    }

    public function handle(
        User $actor,
        FinancialLedgerEntry|int $entry,
        string $reason,
        string $idempotencyKey,
    ): FinancialLedgerEntry {
        $organization = $this->authorization->authorizeManage($actor);
        $entryId = $entry instanceof FinancialLedgerEntry ? (int) $entry->getKey() : $entry;
        $reason = trim($reason);
        $idempotencyKey = trim($idempotencyKey);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите причину возврата бонуса.']);
        }

        if ($idempotencyKey === ''
            || mb_strlen($idempotencyKey) > 180
            || preg_match('/^[A-Za-z0-9._:-]+$/', $idempotencyKey) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => 'Ключ операции указан неверно.']);
        }

        $requestHash = hash('sha256', json_encode([
            'original_id' => $entryId,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $organization, $entryId, $reason, $idempotencyKey, $requestHash): FinancialLedgerEntry {
            $original = FinancialLedgerEntry::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($entryId)
                ->lockForUpdate()
                ->first();

            if (! $original instanceof FinancialLedgerEntry) {
                throw (new ModelNotFoundException)->setModel(FinancialLedgerEntry::class, [$entryId]);
            }

            $idempotency = FinanceIdempotencyKey::query()
                ->where('organization_id', $organization->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($idempotency !== null) {
                $this->assertIdempotency($idempotency, $original, $requestHash);

                return $this->existingResult($idempotency, (int) $organization->getKey());
            }

            $obligationId = (int) $original->getRawOriginal('obligation_id');
            $obligation = FinancialObligation::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($obligationId)
                ->lockForUpdate()
                ->first();

            if (! $obligation instanceof FinancialObligation) {
                throw $this->invalidEntry();
            }

            $beneficiary = Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($obligation->getRawOriginal('client_id'))
                ->lockForUpdate()
                ->first();

            if (! $beneficiary instanceof Client) {
                throw $this->invalidEntry();
            }

            $data = $this->validateOriginal($original, $obligation);
            $redeemed = ReferralRewardLedgerEntry::query()
                ->where('organization_id', $organization->getKey())
                ->where('financial_ledger_entry_id', $original->getKey())
                ->where('entry_type', ReferralRewardLedgerEntryType::Redeemed->value)
                ->where('reward_category', ReferralRewardCategory::ServiceCredit->value)
                ->lockForUpdate()
                ->first();

            if (! $redeemed instanceof ReferralRewardLedgerEntry
                || (int) $redeemed->beneficiary_client_id !== (int) $beneficiary->getKey()
                || (int) $redeemed->financial_obligation_id !== (int) $obligation->getKey()
                || (int) $redeemed->amount_minor !== $data['amount_minor']
                || $redeemed->getRawOriginal('currency') !== $data['currency']->value) {
                throw $this->invalidEntry();
            }
            $restoredMoney = $this->balances->accountingMoney($redeemed);

            if (FinancialLedgerEntry::query()
                ->where('organization_id', $organization->getKey())
                ->where('corrects_ledger_entry_id', $original->getKey())
                ->exists()) {
                throw ValidationException::withMessages(['entry' => 'Этот бонус уже возвращён.']);
            }

            if (ReferralRewardLedgerEntry::query()
                ->where('organization_id', $organization->getKey())
                ->where('reverses_entry_id', $redeemed->getKey())
                ->where('entry_type', ReferralRewardLedgerEntryType::Restored->value)
                ->exists()) {
                throw ValidationException::withMessages(['entry' => 'Этот бонус уже возвращён.']);
            }

            DB::table('finance_idempotency_keys')->insertOrIgnore([
                'organization_id' => $organization->getKey(),
                'idempotency_key' => $idempotencyKey,
                'operation' => 'referral_credit_restore',
                'subject_type' => FinancialLedgerEntry::class,
                'subject_id' => $original->getKey(),
                'request_hash' => $requestHash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $idempotency = FinanceIdempotencyKey::query()
                ->where('organization_id', $organization->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertIdempotency($idempotency, $original, $requestHash);

            if ($idempotency->result_id !== null) {
                return $this->existingResult($idempotency, (int) $organization->getKey());
            }

            $correction = $this->ledger->handle(
                organization: $organization,
                obligation: $obligation,
                data: new FinancialLedgerEntryData(
                    entryType: FinancialLedgerEntryType::Correction,
                    source: FinancialEntrySource::Crm,
                    amountMinor: -$data['amount_minor'],
                    currency: $data['currency'],
                    paymentAmountMinor: -$data['payment_amount_minor'],
                    paymentCurrency: $data['payment_currency'],
                    baseAmountMinor: -$data['base_amount_minor'],
                    baseCurrency: $data['base_currency'],
                    displayAmountMinor: -$data['display_amount_minor'],
                    displayCurrency: $data['display_currency'],
                    settlementAmountMinor: -$data['settlement_amount_minor'],
                    settlementCurrency: $data['settlement_currency'],
                    conversionSnapshot: [
                        'correction_of' => $original->getKey(),
                        'original_snapshot' => $original->conversion_snapshot,
                    ],
                    paymentMethod: null,
                    occurredAt: CarbonImmutable::now(),
                    note: $reason,
                    actorUserId: $actor->getKey(),
                    providerReference: null,
                    idempotencyKey: 'referral_credit.restore:'.$organization->getKey().':'.$idempotencyKey,
                    correctsLedgerEntryId: $original->getKey(),
                ),
            );

            $restored = new ReferralRewardLedgerEntry;
            $restored->forceFill([
                'organization_id' => $organization->getKey(),
                'beneficiary_client_id' => $beneficiary->getKey(),
                'referred_client_id' => null,
                'referral_relationship_id' => null,
                'referral_commercial_evidence_id' => null,
                'financial_obligation_id' => null,
                'financial_ledger_entry_id' => null,
                'reward_program_version_id' => null,
                'entry_type' => ReferralRewardLedgerEntryType::Restored->value,
                'reward_category' => ReferralRewardCategory::ServiceCredit->value,
                'amount_minor' => $restoredMoney->minorUnits(),
                'currency' => $restoredMoney->currency()->value,
                'reason_type' => 'credit_redemption_restore',
                'reason' => $reason,
                'comment' => null,
                'created_by_user_id' => $actor->getKey(),
                'reverses_entry_id' => $redeemed->getKey(),
                'idempotency_key' => 'referral.reward.restored:'.$organization->getKey().':'.$redeemed->getKey(),
                'request_hash' => hash('sha256', $redeemed->getKey().'|'.$reason),
                'occurred_at' => $correction->occurred_at,
            ]);
            $restored->save();

            $this->reconciliation->handle(
                (int) $organization->getKey(),
                (int) $obligation->getKey(),
                true,
            );

            $idempotency->forceFill([
                'result_type' => FinancialLedgerEntry::class,
                'result_id' => $correction->getKey(),
                'updated_at' => now(),
            ])->save();
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'finance.referral_credit.restored',
                targetType: FinancialLedgerEntry::class,
                targetId: (string) $correction->getKey(),
                metadata: [
                    'client_id' => $beneficiary->getKey(),
                    'obligation_id' => $obligation->getKey(),
                    'amount_minor' => $restoredMoney->minorUnits(),
                    'currency' => $restoredMoney->currency()->value,
                    'correction_of' => $original->getKey(),
                    'reason_present' => true,
                ],
            );
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'referral.reward.restored',
                targetType: ReferralRewardLedgerEntry::class,
                targetId: (string) $restored->getKey(),
                metadata: [
                    'client_id' => $beneficiary->getKey(),
                    'obligation_id' => $obligation->getKey(),
                    'financial_ledger_entry_id' => $original->getKey(),
                    'amount_minor' => $data['amount_minor'],
                    'currency' => $data['currency']->value,
                    'correction_of' => $original->getKey(),
                    'reason_present' => true,
                ],
            );

            return $correction->refresh();
        });
    }

    /** @return array{amount_minor: int, currency: CurrencyCode, payment_amount_minor: int, payment_currency: CurrencyCode, base_amount_minor: int, base_currency: CurrencyCode, display_amount_minor: int, display_currency: CurrencyCode, settlement_amount_minor: int, settlement_currency: CurrencyCode} */
    private function validateOriginal(FinancialLedgerEntry $entry, FinancialObligation $obligation): array
    {
        $entryType = FinancialLedgerEntryType::tryFrom((string) $entry->getRawOriginal('entry_type'));
        $source = FinancialEntrySource::tryFrom((string) $entry->getRawOriginal('source'));
        $paymentMethod = (string) $entry->getRawOriginal('payment_method');

        if ($entryType !== FinancialLedgerEntryType::ReferralCredit
            || $source !== FinancialEntrySource::Referral
            || $paymentMethod !== 'referral_credit'
            || $entry->getRawOriginal('corrects_ledger_entry_id') !== null) {
            throw $this->invalidEntry();
        }

        $obligationData = $this->contract->validateObligation($obligation);
        $this->contract->validateLedgerForReconciliation($entry);
        $currencies = [];
        foreach ($this->contract->ledgerCurrencyAttributes() as $attribute) {
            $currencies[$attribute] = $this->contract->currency($entry->getRawOriginal($attribute));
        }

        $amounts = [];
        foreach (['amount_minor', 'payment_amount_minor', 'base_amount_minor', 'display_amount_minor', 'settlement_amount_minor'] as $attribute) {
            $currencyAttribute = match ($attribute) {
                'amount_minor' => 'currency',
                'payment_amount_minor' => 'payment_currency',
                'base_amount_minor' => 'base_currency',
                'display_amount_minor' => 'display_currency',
                default => 'settlement_currency',
            };
            $amounts[$attribute] = $this->contract->money(
                $entry->getRawOriginal($attribute),
                $currencies[$currencyAttribute],
                'A persisted referral credit is invalid.',
            )->minorUnits();
        }

        if ($amounts['amount_minor'] <= 0
            || $amounts['payment_amount_minor'] <= 0
            || $amounts['base_amount_minor'] <= 0
            || $amounts['display_amount_minor'] <= 0
            || $amounts['settlement_amount_minor'] <= 0
            || $currencies['settlement_currency'] !== $obligationData['currencies']['settlement_currency']
            || $currencies['base_currency'] !== $obligationData['currencies']['base_currency']
            || $currencies['display_currency'] !== $obligationData['currencies']['display_currency']) {
            throw $this->invalidEntry();
        }

        return [
            ...$amounts,
            'currency' => $currencies['currency'],
            'payment_currency' => $currencies['payment_currency'],
            'base_currency' => $currencies['base_currency'],
            'display_currency' => $currencies['display_currency'],
            'settlement_currency' => $currencies['settlement_currency'],
        ];
    }

    private function assertIdempotency(FinanceIdempotencyKey $idempotency, FinancialLedgerEntry $entry, string $requestHash): void
    {
        if ($idempotency->operation !== 'referral_credit_restore'
            || $idempotency->subject_type !== FinancialLedgerEntry::class
            || (int) $idempotency->subject_id !== (int) $entry->getKey()
            || $idempotency->request_hash !== $requestHash) {
            throw ValidationException::withMessages(['idempotency_key' => 'Этот ключ уже использован для другой операции.']);
        }
    }

    private function existingResult(FinanceIdempotencyKey $idempotency, int $organizationId): FinancialLedgerEntry
    {
        if ($idempotency->result_id === null) {
            throw ValidationException::withMessages(['idempotency_key' => 'Эта операция ещё обрабатывается.']);
        }

        return FinancialLedgerEntry::query()
            ->where('organization_id', $organizationId)
            ->whereKey($idempotency->result_id)
            ->firstOrFail();
    }

    private function invalidEntry(): ValidationException
    {
        return ValidationException::withMessages([
            'entry' => 'Эту запись бонусной оплаты нельзя вернуть.',
        ]);
    }
}
