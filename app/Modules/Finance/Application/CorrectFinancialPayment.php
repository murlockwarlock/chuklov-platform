<?php

namespace App\Modules\Finance\Application;

use App\Models\User;
use App\Modules\Finance\Domain\Enums\FinancialEntrySource;
use App\Modules\Finance\Domain\Enums\FinancialLedgerEntryType;
use App\Modules\Finance\Domain\Models\FinanceIdempotencyKey;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\ValueObjects\FinancialLedgerEntryData;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class CorrectFinancialPayment
{
    public function __construct(
        private readonly FinanceAuthorization $authorization,
        private readonly AppendFinancialLedgerEntry $ledger,
        private readonly RecordAuditEvent $audit,
        private readonly FinancialReconciliationContract $contract,
        private readonly ReconcileFinancialObligation $reconciliation,
    ) {}

    public function handle(User $actor, FinancialLedgerEntry $original, string $reason, string $idempotencyKey): FinancialLedgerEntry
    {
        $organization = $this->authorization->authorizeManage($actor);
        $this->authorization->assertOwned($original);
        $reason = trim($reason);
        $idempotencyKey = trim($idempotencyKey);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите причину исправления не длиннее 500 символов.']);
        }

        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 180 || preg_match('/^[A-Za-z0-9._:-]+$/', $idempotencyKey) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => 'Ключ операции указан неверно.']);
        }

        $requestHash = hash('sha256', json_encode([
            'original_id' => $original->getKey(),
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $organization, $original, $reason, $idempotencyKey, $requestHash): FinancialLedgerEntry {
            $lockedOriginal = FinancialLedgerEntry::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($original->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $idempotency = FinanceIdempotencyKey::query()
                ->where('organization_id', $organization->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($idempotency !== null) {
                $this->assertMatchingIdempotency($idempotency, $lockedOriginal, $requestHash);

                if ($idempotency->result_id === null) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Эта операция ещё обрабатывается.']);
                }

                return FinancialLedgerEntry::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($idempotency->result_id)
                    ->firstOrFail();
            }

            $lockedObligation = FinancialObligation::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($lockedOriginal->getRawOriginal('obligation_id'))
                ->lockForUpdate()
                ->first();

            if ($lockedObligation === null) {
                throw $this->invalidCorrection();
            }

            try {
                $originalData = $this->contract->validateCorrectableLedgerEntry($lockedOriginal, $lockedObligation);
                $this->reconciliation->handle(
                    (int) $organization->getKey(),
                    (int) $lockedObligation->getKey(),
                    true,
                );
            } catch (UnexpectedValueException) {
                throw $this->invalidCorrection();
            }

            if (FinancialLedgerEntry::query()
                ->where('organization_id', $organization->getKey())
                ->where('corrects_ledger_entry_id', $lockedOriginal->getKey())
                ->exists()) {
                throw ValidationException::withMessages(['payment' => 'Для этой записи уже есть исправление.']);
            }

            DB::table('finance_idempotency_keys')->insertOrIgnore([
                'organization_id' => $organization->getKey(),
                'idempotency_key' => $idempotencyKey,
                'operation' => 'financial_correction',
                'subject_type' => FinancialLedgerEntry::class,
                'subject_id' => $lockedOriginal->getKey(),
                'request_hash' => $requestHash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $idempotency = FinanceIdempotencyKey::query()
                ->where('organization_id', $organization->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertMatchingIdempotency($idempotency, $lockedOriginal, $requestHash);

            if ($idempotency->result_id !== null) {
                return FinancialLedgerEntry::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($idempotency->result_id)
                    ->firstOrFail();
            }

            $entry = $this->ledger->handle(
                organization: $organization,
                obligation: $lockedObligation,
                data: new FinancialLedgerEntryData(
                    entryType: FinancialLedgerEntryType::Correction,
                    source: FinancialEntrySource::Crm,
                    amountMinor: -$originalData['amount_minor'],
                    currency: $originalData['currency'],
                    paymentAmountMinor: -$originalData['payment_amount_minor'],
                    paymentCurrency: $originalData['payment_currency'],
                    baseAmountMinor: -$originalData['base_amount_minor'],
                    baseCurrency: $originalData['base_currency'],
                    displayAmountMinor: -$originalData['display_amount_minor'],
                    displayCurrency: $originalData['display_currency'],
                    settlementAmountMinor: -$originalData['settlement_amount_minor'],
                    settlementCurrency: $originalData['settlement_currency'],
                    conversionSnapshot: [
                        'correction_of' => $lockedOriginal->getKey(),
                        'original_snapshot' => $lockedOriginal->conversion_snapshot,
                    ],
                    paymentMethod: null,
                    occurredAt: CarbonImmutable::now(),
                    note: $reason,
                    actorUserId: $actor->getKey(),
                    providerReference: null,
                    idempotencyKey: 'correction:'.$organization->getKey().':'.$idempotencyKey,
                    correctsLedgerEntryId: $lockedOriginal->getKey(),
                ),
            );
            $idempotency->forceFill([
                'result_type' => FinancialLedgerEntry::class,
                'result_id' => $entry->getKey(),
                'updated_at' => now(),
            ])->save();
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'finance.payment.corrected',
                targetType: FinancialLedgerEntry::class,
                targetId: (string) $entry->getKey(),
                metadata: [
                    'source' => 'crm',
                    'correction_of' => $lockedOriginal->getKey(),
                    'reason_present' => true,
                ],
            );

            return $entry;
        });
    }

    private function assertMatchingIdempotency(
        FinanceIdempotencyKey $idempotency,
        FinancialLedgerEntry $original,
        string $requestHash,
    ): void {
        if ($idempotency->operation !== 'financial_correction'
            || $idempotency->subject_type !== FinancialLedgerEntry::class
            || $idempotency->subject_id !== $original->getKey()
            || $idempotency->request_hash !== $requestHash) {
            throw ValidationException::withMessages(['idempotency_key' => 'Этот ключ уже использован для другой операции.']);
        }
    }

    private function invalidCorrection(): ValidationException
    {
        return ValidationException::withMessages([
            'payment' => 'Эту запись нельзя исправить повторной проводкой.',
        ]);
    }
}
