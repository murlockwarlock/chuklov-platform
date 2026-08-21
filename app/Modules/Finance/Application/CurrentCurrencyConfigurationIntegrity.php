<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
use App\Modules\Finance\Domain\Models\OrganizationCurrencyConfiguration;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CurrentCurrencyConfigurationIntegrity
{
    public function __construct(private readonly CurrencyCatalog $catalog) {}

    /**
     * @return array{
     *     base: CurrencyCode,
     *     display: CurrencyCode,
     *     force_single: bool,
     *     rounding: FinancialRoundingMode,
     *     allowed: list<CurrencyCode>,
     *     rates: list<array{source: CurrencyCode, target: CurrencyCode, rate: string}>,
     *     rate_map: array<string, string>
     * }|null
     */
    public function inspect(?OrganizationCurrencyConfiguration $configuration, int $organizationId): ?array
    {
        $allowedRows = DB::table('organization_allowed_currencies')
            ->where('organization_id', $organizationId)
            ->orderBy('currency')
            ->pluck('currency')
            ->all();
        $rateRows = DB::table('organization_exchange_rates')
            ->where('organization_id', $organizationId)
            ->orderBy('source_currency')
            ->orderBy('target_currency')
            ->get(['source_currency', 'target_currency', 'rate']);

        if ($configuration === null) {
            if ($allowedRows !== [] || $rateRows->isNotEmpty()) {
                throw new InvalidArgumentException('The current currency configuration is incomplete.');
            }

            return null;
        }

        $base = $this->currency($configuration->getRawOriginal('base_currency'));
        $display = $this->currency($configuration->getRawOriginal('display_currency'));
        $forceSingle = $this->forceSingle($configuration->getRawOriginal('force_single_currency'));
        $rounding = $this->roundingMode($configuration->getRawOriginal('rounding_mode'));
        $allowed = [];

        foreach ($allowedRows as $rawCurrency) {
            $currency = $this->currency($rawCurrency);

            if (in_array($currency, $allowed, true)) {
                throw new InvalidArgumentException('The current currency configuration contains duplicate currencies.');
            }

            $allowed[] = $currency;
        }

        if ($allowed === [] || ! in_array($base, $allowed, true) || ! in_array($display, $allowed, true)
            || ($forceSingle && ($base !== $display || $allowed !== [$base]))) {
            throw new InvalidArgumentException('The current currency configuration is invalid.');
        }

        $rates = [];
        $rateMap = [];

        foreach ($rateRows as $row) {
            $source = $this->currency($row->source_currency);
            $target = $this->currency($row->target_currency);
            $rate = $this->rate($row->rate);

            if ($source === $target) {
                throw new InvalidArgumentException('The current exchange rate is invalid.');
            }

            $key = $source->value.'>'.$target->value;

            if (array_key_exists($key, $rateMap)) {
                throw new InvalidArgumentException('The current exchange rates are duplicated.');
            }

            $rateMap[$key] = $rate;
            $rates[] = [
                'source' => $source,
                'target' => $target,
                'rate' => $rate,
            ];
        }

        foreach ($allowed as $source) {
            foreach ([$base, $display] as $target) {
                if ($source === $target) {
                    continue;
                }

                if (! array_key_exists($source->value.'>'.$target->value, $rateMap)) {
                    throw new InvalidArgumentException('The current currency configuration is incomplete.');
                }
            }
        }

        return [
            'base' => $base,
            'display' => $display,
            'force_single' => $forceSingle,
            'rounding' => $rounding,
            'allowed' => $allowed,
            'rates' => $rates,
            'rate_map' => $rateMap,
        ];
    }

    private function currency(mixed $value): CurrencyCode
    {
        if (! is_string($value) || CurrencyCode::tryFrom($value) === null) {
            throw new InvalidArgumentException('The current currency is invalid.');
        }

        return $this->catalog->code($value);
    }

    private function forceSingle(mixed $value): bool
    {
        return match ($value) {
            true, 1, '1' => true,
            false, 0, '0' => false,
            default => throw new InvalidArgumentException('The current currency mode is invalid.'),
        };
    }

    private function roundingMode(mixed $value): FinancialRoundingMode
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('The current currency rounding mode is invalid.');
        }

        return FinancialRoundingMode::tryFrom($value)
            ?? throw new InvalidArgumentException('The current currency rounding mode is invalid.');
    }

    private function rate(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException('The current exchange rate is invalid.');
        }

        $rate = (string) $value;

        if (preg_match('/^(0|[1-9][0-9]{0,19})(\\.[0-9]{1,18})?$/', $rate) !== 1
            || BigDecimal::of($rate)->isNegativeOrZero()) {
            throw new InvalidArgumentException('The current exchange rate is invalid.');
        }

        return $rate;
    }
}
