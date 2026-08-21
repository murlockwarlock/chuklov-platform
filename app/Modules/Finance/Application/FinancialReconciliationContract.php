<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialEntrySource;
use App\Modules\Finance\Domain\Enums\FinancialLedgerEntryType;
use App\Modules\Finance\Domain\Enums\PaymentMethod;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Finance\Domain\ValueObjects\Money;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use UnexpectedValueException;

final class FinancialReconciliationContract
{
    private const OBLIGATION_CURRENCY_ATTRIBUTES = [
        'currency',
        'base_currency',
        'display_currency',
        'payment_currency',
        'settlement_currency',
    ];

    private const LEDGER_CURRENCY_ATTRIBUTES = [
        'currency',
        'payment_currency',
        'base_currency',
        'display_currency',
        'settlement_currency',
    ];

    public function __construct(private readonly CurrencyCatalog $catalog) {}

    /** @return list<string> */
    public function obligationCurrencyAttributes(): array
    {
        return self::OBLIGATION_CURRENCY_ATTRIBUTES;
    }

    /** @return list<string> */
    public function ledgerCurrencyAttributes(): array
    {
        return self::LEDGER_CURRENCY_ATTRIBUTES;
    }

    /** @return array{positive: list<string>, non_negative: list<string>} */
    public function obligationAmountInvariants(): array
    {
        return [
            'positive' => ['amount_minor', 'payment_amount_minor', 'settlement_amount_minor'],
            'non_negative' => ['base_amount_minor', 'display_amount_minor'],
        ];
    }

    /** @return list<string> */
    public function currencyValues(): array
    {
        return array_map(
            static fn (CurrencyCode $currency): string => $currency->value,
            $this->catalog->codes(),
        );
    }

    public function normalizedCurrencySql(string $expression): string
    {
        return 'UPPER(TRIM('.$expression.'))';
    }

    public function ratePattern(): string
    {
        return '^(?:0|[1-9][0-9]{0,19})(?:\.[0-9]{1,18})?$';
    }

    public function integerRatePattern(): string
    {
        return '^(?:0|[1-9][0-9]{0,19})$';
    }

    public function validIntegerSql(string $expression): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "({$expression})::text ~ '^-?(0|[1-9][0-9]*)$'",
            default => "typeof({$expression}) = 'integer'",
        };
    }

    public function rate(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new UnexpectedValueException('A persisted financial rate is invalid.');
        }

        $rate = (string) $value;

        if (preg_match('/'.$this->ratePattern().'/', $rate) !== 1) {
            throw new UnexpectedValueException('A persisted financial rate is invalid.');
        }

        try {
            if (BigDecimal::of($rate)->isNegativeOrZero()) {
                throw new UnexpectedValueException('A persisted financial rate is invalid.');
            }
        } catch (MathException $exception) {
            throw new UnexpectedValueException('A persisted financial rate is invalid.', previous: $exception);
        }

        return $rate;
    }

    public function currency(mixed $value): CurrencyCode
    {
        try {
            return $this->catalog->code($value);
        } catch (InvalidArgumentException $exception) {
            throw new UnexpectedValueException('A persisted financial currency is invalid.', previous: $exception);
        }
    }

    public function tryCurrency(mixed $value): ?CurrencyCode
    {
        try {
            return $this->currency($value);
        } catch (UnexpectedValueException) {
            return null;
        }
    }

    /**
     * @return array{
     *     currencies: array{currency: CurrencyCode, base_currency: CurrencyCode, display_currency: CurrencyCode, payment_currency: CurrencyCode, settlement_currency: CurrencyCode},
     *     amounts: array{amount_minor: int, base_amount_minor: int, display_amount_minor: int, payment_amount_minor: int, settlement_amount_minor: int}
     * }
     */
    public function validateObligation(FinancialObligation $obligation): array
    {
        $currencies = [
            'currency' => $this->currency($obligation->getRawOriginal('currency')),
            'base_currency' => $this->currency($obligation->getRawOriginal('base_currency')),
            'display_currency' => $this->currency($obligation->getRawOriginal('display_currency')),
            'payment_currency' => $this->currency($obligation->getRawOriginal('payment_currency')),
            'settlement_currency' => $this->currency($obligation->getRawOriginal('settlement_currency')),
        ];
        $amounts = [
            'amount_minor' => $this->money($obligation->getRawOriginal('amount_minor'), $currencies['currency'], 'A persisted financial amount is invalid.')->minorUnits(),
            'base_amount_minor' => $this->money($obligation->getRawOriginal('base_amount_minor'), $currencies['base_currency'], 'A persisted financial amount is invalid.')->minorUnits(),
            'display_amount_minor' => $this->money($obligation->getRawOriginal('display_amount_minor'), $currencies['display_currency'], 'A persisted financial amount is invalid.')->minorUnits(),
            'payment_amount_minor' => $this->money($obligation->getRawOriginal('payment_amount_minor'), $currencies['payment_currency'], 'A persisted financial amount is invalid.')->minorUnits(),
            'settlement_amount_minor' => $this->money($obligation->getRawOriginal('settlement_amount_minor'), $currencies['settlement_currency'], 'A persisted financial amount is invalid.')->minorUnits(),
        ];

        $invariants = $this->obligationAmountInvariants();

        foreach ($invariants['positive'] as $attribute) {
            if ($amounts[$attribute] <= 0) {
                throw new UnexpectedValueException('A persisted financial amount is not positive.');
            }
        }

        foreach ($invariants['non_negative'] as $attribute) {
            if ($amounts[$attribute] < 0) {
                throw new UnexpectedValueException('A persisted financial amount is negative.');
            }
        }

        return [
            'currencies' => $currencies,
            'amounts' => $amounts,
        ];
    }

    /**
     * @return array{
     *     currencies: array{currency: CurrencyCode, payment_currency: CurrencyCode, base_currency: CurrencyCode, display_currency: CurrencyCode, settlement_currency: CurrencyCode},
     *     settlement_amount_minor: int
     * }
     */
    public function validateLedgerForReconciliation(FinancialLedgerEntry $entry): array
    {
        $currencies = [
            'currency' => $this->currency($entry->getRawOriginal('currency')),
            'payment_currency' => $this->currency($entry->getRawOriginal('payment_currency')),
            'base_currency' => $this->currency($entry->getRawOriginal('base_currency')),
            'display_currency' => $this->currency($entry->getRawOriginal('display_currency')),
            'settlement_currency' => $this->currency($entry->getRawOriginal('settlement_currency')),
        ];
        $settlement = $this->money(
            $entry->getRawOriginal('settlement_amount_minor'),
            $currencies['settlement_currency'],
            'A persisted ledger settlement amount is invalid.',
        )->minorUnits();

        if ($settlement === 0) {
            throw new UnexpectedValueException('A persisted ledger settlement amount is zero.');
        }

        return [
            'currencies' => $currencies,
            'settlement_amount_minor' => $settlement,
        ];
    }

    /**
     * @return array{
     *     entry_type: FinancialLedgerEntryType,
     *     source: FinancialEntrySource,
     *     amount_minor: int,
     *     currency: CurrencyCode,
     *     payment_amount_minor: int,
     *     payment_currency: CurrencyCode,
     *     base_amount_minor: int,
     *     base_currency: CurrencyCode,
     *     display_amount_minor: int,
     *     display_currency: CurrencyCode,
     *     settlement_amount_minor: int,
     *     settlement_currency: CurrencyCode
     * }
     */
    public function validateCorrectableLedgerEntry(FinancialLedgerEntry $entry): array
    {
        $entryType = is_string($entry->getRawOriginal('entry_type'))
            ? FinancialLedgerEntryType::tryFrom($entry->getRawOriginal('entry_type'))
            : null;
        $source = is_string($entry->getRawOriginal('source'))
            ? FinancialEntrySource::tryFrom($entry->getRawOriginal('source'))
            : null;

        if ($entryType === null || $source === null || $entry->getRawOriginal('corrects_ledger_entry_id') !== null) {
            throw new UnexpectedValueException('The ledger entry cannot be corrected.');
        }

        $expectedSource = match ($entryType) {
            FinancialLedgerEntryType::ManualPayment => FinancialEntrySource::Crm,
            FinancialLedgerEntryType::FakeGatewaySettlement => FinancialEntrySource::FakeGateway,
            FinancialLedgerEntryType::Correction => null,
        };

        if ($expectedSource !== $source) {
            throw new UnexpectedValueException('The ledger entry cannot be corrected.');
        }

        $currencies = $this->modelCurrencies($entry, self::LEDGER_CURRENCY_ATTRIBUTES);
        $amounts = [
            'amount_minor' => $this->money($entry->getRawOriginal('amount_minor'), $currencies['currency'], 'A persisted ledger amount is invalid.')->minorUnits(),
            'payment_amount_minor' => $this->money($entry->getRawOriginal('payment_amount_minor'), $currencies['payment_currency'], 'A persisted ledger payment amount is invalid.')->minorUnits(),
            'base_amount_minor' => $this->money($entry->getRawOriginal('base_amount_minor'), $currencies['base_currency'], 'A persisted ledger base amount is invalid.')->minorUnits(),
            'display_amount_minor' => $this->money($entry->getRawOriginal('display_amount_minor'), $currencies['display_currency'], 'A persisted ledger display amount is invalid.')->minorUnits(),
            'settlement_amount_minor' => $this->money($entry->getRawOriginal('settlement_amount_minor'), $currencies['settlement_currency'], 'A persisted ledger settlement amount is invalid.')->minorUnits(),
        ];

        if ($entryType === FinancialLedgerEntryType::ManualPayment
            && ! is_string($entry->getRawOriginal('payment_method'))) {
            throw new UnexpectedValueException('The manual ledger payment method is invalid.');
        }

        if ($entry->getRawOriginal('payment_method') !== null
            && (! is_string($entry->getRawOriginal('payment_method')) || PaymentMethod::tryFrom($entry->getRawOriginal('payment_method')) === null)) {
            throw new UnexpectedValueException('The ledger payment method is invalid.');
        }

        if ($amounts['amount_minor'] <= 0
            || $amounts['payment_amount_minor'] <= 0
            || $amounts['settlement_amount_minor'] <= 0
            || $amounts['base_amount_minor'] < 0
            || $amounts['display_amount_minor'] < 0) {
            throw new UnexpectedValueException('The ledger entry amounts cannot be corrected.');
        }

        return [
            'entry_type' => $entryType,
            'source' => $source,
            ...$amounts,
            'currency' => $currencies['currency'],
            'payment_currency' => $currencies['payment_currency'],
            'base_currency' => $currencies['base_currency'],
            'display_currency' => $currencies['display_currency'],
            'settlement_currency' => $currencies['settlement_currency'],
        ];
    }

    public function money(mixed $value, CurrencyCode $currency, string $message): Money
    {
        if (! is_int($value) && ! is_string($value)) {
            throw new UnexpectedValueException($message);
        }

        try {
            return Money::ofMinor($value, $currency);
        } catch (InvalidArgumentException $exception) {
            throw new UnexpectedValueException($message, previous: $exception);
        }
    }

    /** @param list<string> $attributes
     * @return array<string, CurrencyCode>
     */
    private function modelCurrencies(FinancialLedgerEntry|FinancialObligation $model, array $attributes): array
    {
        $currencies = [];

        foreach ($attributes as $attribute) {
            $currencies[$attribute] = $this->currency($model->getRawOriginal($attribute));
        }

        return $currencies;
    }
}
