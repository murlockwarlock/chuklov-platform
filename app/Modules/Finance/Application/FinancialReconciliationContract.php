<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialEntrySource;
use App\Modules\Finance\Domain\Enums\FinancialLedgerEntryType;
use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
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

    public function canonicalCurrencySql(string $expression): string
    {
        return $expression;
    }

    public function currencyScaleSql(string $expression): string
    {
        $cases = array_map(
            fn (CurrencyCode $currency): string => "WHEN '{$currency->value}' THEN ".$this->catalog->scale($currency),
            $this->catalog->codes(),
        );

        return '(CASE '.$expression.' '.implode(' ', $cases).' END)';
    }

    public function minorScaleFactorSql(string $sourceCurrency, string $targetCurrency): string
    {
        $sourceScale = $this->currencyScaleSql($sourceCurrency);
        $targetScale = $this->currencyScaleSql($targetCurrency);
        $scalePairs = [];
        $numericCast = DB::getDriverName() === 'pgsql' ? '::numeric' : '';

        foreach ($this->catalog->codes() as $source) {
            foreach ($this->catalog->codes() as $target) {
                $sourceValue = $this->catalog->scale($source);
                $targetValue = $this->catalog->scale($target);
                $scalePairs[$sourceValue.':'.$targetValue] = [$sourceValue, $targetValue];
            }
        }

        $cases = [];

        foreach ($scalePairs as [$sourceValue, $targetValue]) {
            $difference = $targetValue - $sourceValue;
            $factor = $difference >= 0
                ? '1'.str_repeat('0', $difference)
                : '0.'.str_repeat('0', -$difference - 1).'1';
            $cases[] = 'WHEN '.$sourceScale.' = '.$sourceValue
                .' AND '.$targetScale.' = '.$targetValue.' THEN '.$factor.$numericCast;
        }

        return '(CASE '.implode(' ', $cases).' END)';
    }

    public function ratePattern(): string
    {
        return '^(0|[1-9][0-9]{0,19})(\\.[0-9]{0,17}[1-9])?$';
    }

    public function snapshotMinorPattern(): string
    {
        return '^(0|[1-9][0-9]*)$';
    }

    public function validIntegerSql(string $expression): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "({$expression})::text ~ '^(0|[1-9][0-9]*|-[1-9][0-9]*)$'",
            default => "typeof({$expression}) = 'integer'",
        };
    }

    public function rate(mixed $value): string
    {
        if (! is_string($value) || preg_match('/'.$this->ratePattern().'/', $value) !== 1) {
            throw new UnexpectedValueException('A persisted financial rate is invalid.');
        }

        try {
            if (BigDecimal::of($value)->isNegativeOrZero()) {
                throw new UnexpectedValueException('A persisted financial rate is invalid.');
            }
        } catch (MathException $exception) {
            throw new UnexpectedValueException('A persisted financial rate is invalid.', previous: $exception);
        }

        return $value;
    }

    public function currency(mixed $value): CurrencyCode
    {
        if (! is_string($value) || CurrencyCode::tryFrom($value) === null) {
            throw new UnexpectedValueException('A persisted financial currency is invalid.');
        }

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
     *     source_currency: CurrencyCode,
     *     target_currency: CurrencyCode,
     *     source_amount: Money,
     *     target_amount: Money,
     *     rate: string,
     *     rounding_mode: FinancialRoundingMode,
     *     source_scale: int,
     *     target_scale: int
     * }
     */
    public function validateValuationSnapshot(mixed $snapshot): array
    {
        if (! is_array($snapshot)) {
            throw new UnexpectedValueException('The obligation valuation snapshot is invalid.');
        }

        try {
            $sourceCurrency = $this->currency($snapshot['source_currency'] ?? null);
            $targetCurrency = $this->currency($snapshot['target_currency'] ?? null);
            $sourceAmount = $this->snapshotMoney($snapshot['source_amount_minor'] ?? null, $sourceCurrency);
            $targetAmount = $this->snapshotMoney($snapshot['target_amount_minor'] ?? null, $targetCurrency);
            $rate = $this->rate($snapshot['rate'] ?? null);

            if (! is_string($snapshot['rounding_mode'] ?? null)) {
                throw new UnexpectedValueException('The obligation valuation snapshot is invalid.');
            }

            $roundingMode = FinancialRoundingMode::fromMixed($snapshot['rounding_mode']);
            $sourceScale = $this->snapshotScale($snapshot['source_scale'] ?? null, $sourceCurrency);
            $targetScale = $this->snapshotScale($snapshot['target_scale'] ?? null, $targetCurrency);
        } catch (InvalidArgumentException $exception) {
            throw new UnexpectedValueException('The obligation valuation snapshot is invalid.', previous: $exception);
        }

        if ($sourceCurrency === $targetCurrency
            && ($rate !== '1' || $sourceAmount->minorUnits() !== $targetAmount->minorUnits())) {
            throw new UnexpectedValueException('The obligation valuation snapshot is invalid.');
        }

        return [
            'source_currency' => $sourceCurrency,
            'target_currency' => $targetCurrency,
            'source_amount' => $sourceAmount,
            'target_amount' => $targetAmount,
            'rate' => $rate,
            'rounding_mode' => $roundingMode,
            'source_scale' => $sourceScale,
            'target_scale' => $targetScale,
        ];
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
    public function validateCorrectableLedgerEntry(
        FinancialLedgerEntry $entry,
        FinancialObligation $obligation,
    ): array {
        $entryType = is_string($entry->getRawOriginal('entry_type'))
            ? FinancialLedgerEntryType::tryFrom($entry->getRawOriginal('entry_type'))
            : null;
        $source = is_string($entry->getRawOriginal('source'))
            ? FinancialEntrySource::tryFrom($entry->getRawOriginal('source'))
            : null;

        if ($entryType !== FinancialLedgerEntryType::ManualPayment
            || $source !== FinancialEntrySource::Crm
            || $entry->getRawOriginal('corrects_ledger_entry_id') !== null) {
            throw new UnexpectedValueException('The ledger entry cannot be corrected.');
        }

        if (! is_string($entry->getRawOriginal('payment_method'))
            || PaymentMethod::tryFrom($entry->getRawOriginal('payment_method')) === null) {
            throw new UnexpectedValueException('The ledger payment method is invalid.');
        }

        $obligationData = $this->validateObligation($obligation);
        $currencies = $this->modelCurrencies($entry, self::LEDGER_CURRENCY_ATTRIBUTES);
        $amounts = [
            'amount_minor' => $this->money($entry->getRawOriginal('amount_minor'), $currencies['currency'], 'A persisted ledger amount is invalid.')->minorUnits(),
            'payment_amount_minor' => $this->money($entry->getRawOriginal('payment_amount_minor'), $currencies['payment_currency'], 'A persisted ledger payment amount is invalid.')->minorUnits(),
            'base_amount_minor' => $this->money($entry->getRawOriginal('base_amount_minor'), $currencies['base_currency'], 'A persisted ledger base amount is invalid.')->minorUnits(),
            'display_amount_minor' => $this->money($entry->getRawOriginal('display_amount_minor'), $currencies['display_currency'], 'A persisted ledger display amount is invalid.')->minorUnits(),
            'settlement_amount_minor' => $this->money($entry->getRawOriginal('settlement_amount_minor'), $currencies['settlement_currency'], 'A persisted ledger settlement amount is invalid.')->minorUnits(),
        ];

        if ($amounts['amount_minor'] <= 0
            || $amounts['payment_amount_minor'] <= 0
            || $amounts['settlement_amount_minor'] <= 0
            || $amounts['base_amount_minor'] < 0
            || $amounts['display_amount_minor'] < 0
            || $currencies['settlement_currency'] !== $obligationData['currencies']['settlement_currency']
            || $currencies['base_currency'] !== $obligationData['currencies']['base_currency']
            || $currencies['display_currency'] !== $obligationData['currencies']['display_currency']) {
            throw new UnexpectedValueException('The ledger entry cannot be corrected.');
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

    public function money(mixed $value, CurrencyCode $currency, string $message): Money
    {
        if (! is_int($value)
            && (! is_string($value) || preg_match('/^(0|[1-9][0-9]*|-[1-9][0-9]*)$/', $value) !== 1)) {
            throw new UnexpectedValueException($message);
        }

        try {
            return Money::ofMinor($value, $currency);
        } catch (InvalidArgumentException $exception) {
            throw new UnexpectedValueException($message, previous: $exception);
        }
    }

    private function snapshotMoney(mixed $value, CurrencyCode $currency): Money
    {
        if (! is_string($value) || preg_match('/'.$this->snapshotMinorPattern().'/', $value) !== 1) {
            throw new UnexpectedValueException('The obligation valuation snapshot is invalid.');
        }

        return $this->money($value, $currency, 'The obligation valuation snapshot is invalid.');
    }

    private function snapshotScale(mixed $value, CurrencyCode $currency): int
    {
        $expected = $this->catalog->scale($currency);

        if (! is_int($value) || $value !== $expected) {
            throw new UnexpectedValueException('The obligation valuation snapshot is invalid.');
        }

        return $value;
    }

    /**
     * @param  list<string>  $attributes
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
