<?php

namespace App\Modules\Finance\Application;

use App\Models\User;
use App\Modules\Finance\Domain\Enums\FinancialEntrySource;
use App\Modules\Finance\Domain\Enums\FinancialLedgerEntryType;
use App\Modules\Finance\Domain\Models\FinanceAccount;
use App\Modules\Finance\Domain\Models\FinanceExpenseCategory;
use App\Modules\Finance\Domain\Models\FinanceIdempotencyKey;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Finance\Domain\ValueObjects\FinancialLedgerEntryData;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

final class RecordExpense
{
    public function __construct(
        private readonly FinanceAuthorization $authorization,
        private readonly CurrencyConfigurationService $configuration,
        private readonly CurrencyCatalog $catalog,
        private readonly AppendFinancialLedgerEntry $ledger,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        FinanceAccount $account,
        FinanceExpenseCategory $category,
        string|int $amount,
        DateTimeInterface|string $occurredAt,
        ?string $note,
        string $idempotencyKey,
    ): FinancialLedgerEntry {
        $organization = $this->authorization->authorizeManage($actor);
        $organizationId = (int) $organization->getKey();
        $accountId = (int) $account->getKey();
        $categoryId = (int) $category->getKey();
        $account = FinanceAccount::query()
            ->where('organization_id', $organizationId)
            ->whereKey($accountId)
            ->where('is_active', true)
            ->first();
        $category = FinanceExpenseCategory::query()
            ->where('organization_id', $organizationId)
            ->whereKey($categoryId)
            ->where('is_active', true)
            ->first();
        if ($account === null) {
            throw (new ModelNotFoundException)->setModel(FinanceAccount::class, [$accountId]);
        }
        if ($category === null) {
            throw (new ModelNotFoundException)->setModel(FinanceExpenseCategory::class, [$categoryId]);
        }

        try {
            $currency = $this->catalog->code((string) $account->getRawOriginal('currency'));
            $this->configuration->assertAllowed($organization, $currency);
            $money = Money::fromDecimal($amount, $currency);
            $money->assertPositive();
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount' => 'Сумма расхода или валюта указаны неверно.']);
        }

        $occurred = $this->occurredAt($occurredAt);
        $note = $note === null ? null : trim($note);
        if ($note !== null && ($note === '' || mb_strlen($note) > 2000)) {
            throw ValidationException::withMessages(['note' => 'Примечание должно содержать не более 2000 символов.']);
        }
        $key = trim($idempotencyKey);
        if ($key === '' || mb_strlen($key) > 180 || preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => 'Ключ операции указан неверно.']);
        }
        $requestHash = hash('sha256', json_encode([
            'account_id' => $accountId,
            'category_id' => $categoryId,
            'amount' => $money->minorUnitsString(),
            'currency' => $currency->value,
            'occurred_at' => $occurred->toIso8601String(),
            'note' => $note,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $organization, $organizationId, $account, $accountId, $categoryId, $money, $currency, $occurred, $note, $key, $requestHash): FinancialLedgerEntry {
            $idempotency = FinanceIdempotencyKey::query()
                ->where('organization_id', $organizationId)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();
            if ($idempotency !== null) {
                if ($idempotency->operation !== 'expense' || $idempotency->subject_type !== FinanceAccount::class || $idempotency->subject_id !== $account->getKey() || $idempotency->request_hash !== $requestHash) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Этот ключ уже использован для другой операции.']);
                }

                return FinancialLedgerEntry::query()->where('organization_id', $organizationId)->whereKey($idempotency->result_id)->firstOrFail();
            }

            DB::table('finance_idempotency_keys')->insertOrIgnore([
                'organization_id' => $organizationId,
                'idempotency_key' => $key,
                'operation' => 'expense',
                'subject_type' => FinanceAccount::class,
                'subject_id' => $account->getKey(),
                'request_hash' => $requestHash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $idempotency = FinanceIdempotencyKey::query()
                ->where('organization_id', $organizationId)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->firstOrFail();
            if ($idempotency->result_id !== null) {
                return FinancialLedgerEntry::query()->where('organization_id', $organizationId)->whereKey($idempotency->result_id)->firstOrFail();
            }

            $currencyConfiguration = $this->configuration->configuration($organization);
            $base = $this->configuration->convert($organization, $money, $currencyConfiguration->base_currency);
            $display = $this->configuration->convert($organization, $money, $currencyConfiguration->display_currency);
            $negative = static fn (int|string $value): int => -abs((int) $value);
            $entry = $this->ledger->handleGeneral(
                organization: $organization,
                data: new FinancialLedgerEntryData(
                    entryType: FinancialLedgerEntryType::Expense,
                    source: FinancialEntrySource::Crm,
                    amountMinor: $negative($money->minorUnits()),
                    currency: $currency,
                    paymentAmountMinor: $negative($money->minorUnits()),
                    paymentCurrency: $currency,
                    baseAmountMinor: $negative($base->targetAmountMinor),
                    baseCurrency: $base->targetCurrency,
                    displayAmountMinor: $negative($display->targetAmountMinor),
                    displayCurrency: $display->targetCurrency,
                    settlementAmountMinor: $negative($base->targetAmountMinor),
                    settlementCurrency: $base->targetCurrency,
                    conversionSnapshot: ['base' => $base->toArray(), 'display' => $display->toArray()],
                    paymentMethod: null,
                    occurredAt: $occurred,
                    note: $note,
                    actorUserId: $actor->getKey(),
                    providerReference: null,
                    idempotencyKey: 'expense:'.$organizationId.':'.$key,
                    accountId: $accountId,
                    expenseCategoryId: $categoryId,
                ),
            );
            $idempotency->forceFill(['result_type' => FinancialLedgerEntry::class, 'result_id' => $entry->getKey(), 'updated_at' => now()])->save();
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'finance.expense.recorded',
                targetType: FinancialLedgerEntry::class,
                targetId: (string) $entry->getKey(),
                metadata: [
                    'source' => 'crm',
                    'payment_currency' => $currency->value,
                    'base_currency' => $base->targetCurrency->value,
                    'account_id' => $accountId,
                    'category_id' => $categoryId,
                ],
            );

            return $entry;
        });
    }

    private function occurredAt(DateTimeInterface|string $occurredAt): CarbonImmutable
    {
        try {
            return $occurredAt instanceof DateTimeInterface
                ? CarbonImmutable::instance($occurredAt)->utc()
                : CarbonImmutable::parse($occurredAt)->utc();
        } catch (Throwable) {
            throw ValidationException::withMessages(['occurred_at' => 'Дата расхода указана неверно.']);
        }
    }
}
