<?php

namespace App\Modules\Finance\Application;

use App\Models\User;
use App\Modules\Finance\Domain\Enums\FinancialEntrySource;
use App\Modules\Finance\Domain\Enums\FinancialLedgerEntryType;
use App\Modules\Finance\Domain\Models\FinanceIdempotencyKey;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\ValueObjects\FinancialLedgerEntryData;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CorrectFinancialPayment
{
    public function __construct(
        private readonly FinanceAuthorization $authorization,
        private readonly AppendFinancialLedgerEntry $ledger,
        private readonly RecordAuditEvent $audit,
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
            $idempotency = FinanceIdempotencyKey::query()
                ->where('organization_id', $organization->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($idempotency !== null) {
                if ($idempotency->operation !== 'financial_correction'
                    || $idempotency->subject_type !== FinancialLedgerEntry::class
                    || $idempotency->subject_id !== $original->getKey()
                    || $idempotency->request_hash !== $requestHash) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Этот ключ уже использован для другой операции.']);
                }

                return FinancialLedgerEntry::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($idempotency->result_id)
                    ->firstOrFail();
            }

            DB::table('finance_idempotency_keys')->insertOrIgnore([
                'organization_id' => $organization->getKey(),
                'idempotency_key' => $idempotencyKey,
                'operation' => 'financial_correction',
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
            if ($idempotency->result_id !== null) {
                if ($idempotency->operation !== 'financial_correction'
                    || $idempotency->subject_type !== FinancialLedgerEntry::class
                    || $idempotency->subject_id !== $original->getKey()
                    || $idempotency->request_hash !== $requestHash) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Этот ключ уже использован для другой операции.']);
                }

                return FinancialLedgerEntry::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($idempotency->result_id)
                    ->firstOrFail();
            }
            $lockedOriginal = FinancialLedgerEntry::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($original->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedOriginal->entry_type->value, ['manual_payment', 'fake_gateway_settlement'], true)
                || $lockedOriginal->corrects_ledger_entry_id !== null) {
                throw ValidationException::withMessages(['payment' => 'Эту запись нельзя исправить повторной проводкой.']);
            }

            if (FinancialLedgerEntry::query()
                ->where('organization_id', $organization->getKey())
                ->where('corrects_ledger_entry_id', $lockedOriginal->getKey())
                ->exists()) {
                throw ValidationException::withMessages(['payment' => 'Для этой записи уже есть исправление.']);
            }

            $entry = $this->ledger->handle(
                organization: $organization,
                obligation: $lockedOriginal->obligation_id,
                data: new FinancialLedgerEntryData(
                    entryType: FinancialLedgerEntryType::Correction,
                    source: FinancialEntrySource::Crm,
                    amountMinor: -$lockedOriginal->amount_minor,
                    currency: $lockedOriginal->currency,
                    paymentAmountMinor: -$lockedOriginal->payment_amount_minor,
                    paymentCurrency: $lockedOriginal->payment_currency,
                    baseAmountMinor: -$lockedOriginal->base_amount_minor,
                    baseCurrency: $lockedOriginal->base_currency,
                    displayAmountMinor: -$lockedOriginal->display_amount_minor,
                    displayCurrency: $lockedOriginal->display_currency,
                    settlementAmountMinor: -$lockedOriginal->settlement_amount_minor,
                    settlementCurrency: $lockedOriginal->settlement_currency,
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
}
