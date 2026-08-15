<?php

namespace App\Modules\Finance\Application;

use App\Models\User;
use App\Modules\Finance\Domain\Contracts\ReceiptStorage;
use App\Modules\Finance\Domain\Enums\FinancialEntrySource;
use App\Modules\Finance\Domain\Enums\FinancialLedgerEntryType;
use App\Modules\Finance\Domain\Enums\PaymentMethod;
use App\Modules\Finance\Domain\Models\FinanceIdempotencyKey;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Models\FinancialReceipt;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Finance\Domain\ValueObjects\FinancialLedgerEntryData;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

final class RecordManualPayment
{
    public function __construct(
        private readonly FinanceAuthorization $authorization,
        private readonly CurrencyConfigurationService $configuration,
        private readonly CurrencyCatalog $catalog,
        private readonly ReconcileFinancialObligation $reconciliation,
        private readonly AppendFinancialLedgerEntry $ledger,
        private readonly ReceiptStorage $receipts,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        FinancialObligation $obligation,
        string|int $amount,
        string $currency,
        PaymentMethod|string $paymentMethod,
        DateTimeInterface|string $occurredAt,
        ?string $note,
        ?UploadedFile $receipt,
        string $idempotencyKey,
    ): FinancialLedgerEntry {
        $organization = $this->authorization->authorizeManage($actor);
        $this->authorization->assertOwned($obligation);
        $paymentMethod = $paymentMethod instanceof PaymentMethod
            ? $paymentMethod
            : PaymentMethod::tryFrom($paymentMethod);

        if ($paymentMethod === null) {
            throw ValidationException::withMessages(['payment_method' => 'Выберите способ оплаты.']);
        }

        $occurred = $this->occurredAt($occurredAt);
        try {
            $currencyCode = $this->catalog->code($currency);
            $this->configuration->assertAllowed($organization, $currencyCode);
            $money = Money::fromDecimal($amount, $currencyCode);
            $money->assertPositive();
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount' => 'Сумма или валюта оплаты указаны неверно.']);
        }
        $key = trim($idempotencyKey);

        if ($key === '' || mb_strlen($key) > 180 || preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => 'Ключ операции указан неверно.']);
        }

        $requestHash = hash('sha256', json_encode([
            'obligation_id' => $obligation->getKey(),
            'amount' => $money->minorUnitsString(),
            'currency' => $currencyCode->value,
            'payment_method' => $paymentMethod->value,
            'occurred_at' => $occurred->toIso8601String(),
            'note' => $note === null ? null : trim($note),
            'receipt' => $receipt === null ? null : hash_file('sha256', (string) $receipt->getRealPath()),
        ], JSON_THROW_ON_ERROR));
        $storedReceipt = null;

        try {
            return DB::transaction(function () use ($actor, $organization, $obligation, $money, $currencyCode, $paymentMethod, $occurred, $note, $receipt, $key, $requestHash, &$storedReceipt): FinancialLedgerEntry {
                $idempotency = FinanceIdempotencyKey::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('idempotency_key', $key)
                    ->lockForUpdate()
                    ->first();

                if ($idempotency !== null) {
                    if ($idempotency->operation !== 'manual_payment'
                        || $idempotency->subject_type !== FinancialObligation::class
                        || $idempotency->subject_id !== $obligation->getKey()
                        || $idempotency->request_hash !== $requestHash) {
                        throw ValidationException::withMessages(['idempotency_key' => 'Этот ключ уже использован для другой операции.']);
                    }

                    $existing = FinancialLedgerEntry::query()
                        ->where('organization_id', $organization->getKey())
                        ->whereKey($idempotency->result_id)
                        ->first();

                    if ($existing === null) {
                        throw new ModelNotFoundException;
                    }

                    return $existing;
                }

                DB::table('finance_idempotency_keys')->insertOrIgnore([
                    'organization_id' => $organization->getKey(),
                    'idempotency_key' => $key,
                    'operation' => 'manual_payment',
                    'subject_type' => FinancialObligation::class,
                    'subject_id' => $obligation->getKey(),
                    'request_hash' => $requestHash,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $idempotency = FinanceIdempotencyKey::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('idempotency_key', $key)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($idempotency->result_id !== null) {
                    if ($idempotency->operation !== 'manual_payment'
                        || $idempotency->subject_type !== FinancialObligation::class
                        || $idempotency->subject_id !== $obligation->getKey()
                        || $idempotency->request_hash !== $requestHash) {
                        throw ValidationException::withMessages(['idempotency_key' => 'Этот ключ уже использован для другой операции.']);
                    }

                    return FinancialLedgerEntry::query()
                        ->where('organization_id', $organization->getKey())
                        ->whereKey($idempotency->result_id)
                        ->firstOrFail();
                }

                $lockedObligation = FinancialObligation::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($obligation->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($lockedObligation === null) {
                    throw (new ModelNotFoundException)->setModel(FinancialObligation::class, [$obligation->getKey()]);
                }

                $current = $this->reconciliation->handle((int) $organization->getKey(), (int) $lockedObligation->getKey(), true);
                $settlementSnapshot = $this->configuration->convert($organization, $money, $lockedObligation->settlement_currency);

                if ((int) $settlementSnapshot->targetAmountMinor > $current->outstanding->minorUnits()) {
                    throw ValidationException::withMessages(['amount' => 'Сумма оплаты не может превышать текущую задолженность.']);
                }

                $baseSnapshot = $this->configuration->convert($organization, $money, $lockedObligation->base_currency);
                $displaySnapshot = $this->configuration->convert($organization, $money, $lockedObligation->display_currency);
                $note = $note === null ? null : trim($note);

                if ($note !== null && ($note === '' || mb_strlen($note) > 2000)) {
                    throw ValidationException::withMessages(['note' => 'Примечание должно содержать не более 2000 символов.']);
                }

                if ($receipt !== null) {
                    $storedReceipt = $this->receipts->store((int) $organization->getKey(), $receipt);
                }

                $entry = $this->ledger->handle(
                    organization: $organization,
                    obligation: $lockedObligation,
                    data: new FinancialLedgerEntryData(
                        entryType: FinancialLedgerEntryType::ManualPayment,
                        source: FinancialEntrySource::Crm,
                        amountMinor: $money->minorUnits(),
                        currency: $currencyCode,
                        paymentAmountMinor: $money->minorUnits(),
                        paymentCurrency: $currencyCode,
                        baseAmountMinor: (int) $baseSnapshot->targetAmountMinor,
                        baseCurrency: $baseSnapshot->targetCurrency,
                        displayAmountMinor: (int) $displaySnapshot->targetAmountMinor,
                        displayCurrency: $displaySnapshot->targetCurrency,
                        settlementAmountMinor: (int) $settlementSnapshot->targetAmountMinor,
                        settlementCurrency: $settlementSnapshot->targetCurrency,
                        conversionSnapshot: [
                            'base' => $baseSnapshot->toArray(),
                            'display' => $displaySnapshot->toArray(),
                            'settlement' => $settlementSnapshot->toArray(),
                        ],
                        paymentMethod: $paymentMethod,
                        occurredAt: $occurred,
                        note: $note,
                        actorUserId: $actor->getKey(),
                        providerReference: null,
                        idempotencyKey: 'manual_payment:'.$organization->getKey().':'.$key,
                    ),
                );

                if ($storedReceipt !== null) {
                    $receiptRecord = new FinancialReceipt;
                    $receiptRecord->forceFill([
                        'organization_id' => $organization->getKey(),
                        'ledger_entry_id' => $entry->getKey(),
                        'disk' => $storedReceipt->disk,
                        'path' => $storedReceipt->path,
                        'original_name' => $storedReceipt->originalName,
                        'mime_type' => $storedReceipt->mimeType,
                        'size_bytes' => $storedReceipt->sizeBytes,
                        'uploaded_by_user_id' => $actor->getKey(),
                    ]);
                    $receiptRecord->save();
                }

                $idempotency->forceFill([
                    'result_type' => FinancialLedgerEntry::class,
                    'result_id' => $entry->getKey(),
                    'updated_at' => now(),
                ])->save();
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'finance.manual_payment.recorded',
                    targetType: FinancialLedgerEntry::class,
                    targetId: (string) $entry->getKey(),
                    metadata: [
                        'source' => 'crm',
                        'payment_method' => $paymentMethod->value,
                        'payment_currency' => $currencyCode->value,
                        'settlement_currency' => $settlementSnapshot->targetCurrency->value,
                        'receipt_attached' => $storedReceipt !== null,
                    ],
                );

                return $entry;
            });
        } catch (Throwable $exception) {
            if ($storedReceipt !== null) {
                $this->receipts->delete($storedReceipt->path);
            }

            throw $exception;
        }
    }

    private function occurredAt(DateTimeInterface|string $occurredAt): CarbonImmutable
    {
        try {
            return $occurredAt instanceof DateTimeInterface
                ? CarbonImmutable::instance($occurredAt)->utc()
                : CarbonImmutable::parse($occurredAt)->utc();
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['occurred_at' => 'Время оплаты указано неверно.']);
        }
    }
}
