<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
use App\Modules\Finance\Domain\Models\OrganizationCurrencyConfiguration;
use App\Modules\Finance\Domain\Models\OrganizationExchangeRate;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Finance\Domain\ValueObjects\MoneyConversionSnapshot;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Services\Domain\Models\Service;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CurrencyConfigurationService
{
    public function __construct(private readonly CurrencyCatalog $catalog) {}

    public function configuration(Organization|int $organization): OrganizationCurrencyConfiguration
    {
        $organizationId = $organization instanceof Organization ? (int) $organization->getKey() : $organization;

        return OrganizationCurrencyConfiguration::query()
            ->where('organization_id', $organizationId)
            ->firstOrFail();
    }

    /** @return list<CurrencyCode> */
    public function allowedCurrencies(Organization|int $organization): array
    {
        $organizationId = $organization instanceof Organization ? (int) $organization->getKey() : $organization;

        return array_values(DB::table('organization_allowed_currencies')
            ->where('organization_id', $organizationId)
            ->orderBy('currency')
            ->pluck('currency')
            ->map(fn (mixed $currency): CurrencyCode => $this->catalog->code($currency))
            ->all());
    }

    public function assertAllowed(Organization|int $organization, CurrencyCode|string $currency): CurrencyCode
    {
        $code = $this->catalog->code($currency);

        if (! in_array($code, $this->allowedCurrencies($organization), true)) {
            throw new InvalidArgumentException('The currency is not enabled for this organization.');
        }

        return $code;
    }

    public function isServicePriceAvailable(Organization|int $organization, Service $service): bool
    {
        $allowed = $this->allowedCurrencies($organization);
        $priceCurrency = $service->getAttribute('price_currency');

        if ($service->price_minor !== null && is_string($priceCurrency)) {
            try {
                if (in_array($this->catalog->code($priceCurrency), $allowed, true)) {
                    return true;
                }
            } catch (InvalidArgumentException) {
            }
        }

        foreach ((array) $service->getAttribute('price_matrix') as $currency => $amount) {
            try {
                if (is_string($currency) && is_numeric($amount)
                    && (int) $amount > 0
                    && in_array($this->catalog->code($currency), $allowed, true)) {
                    return true;
                }
            } catch (InvalidArgumentException) {
            }
        }

        $priceMatrix = $service->getAttribute('price_matrix');

        return $service->price_minor === null && ($priceMatrix === null || $priceMatrix === []);
    }

    /**
     * @param  list<CurrencyCode>  $allowed
     * @param  array<string, string>  $rates
     */
    public function assertConfiguration(
        Organization|int $organization,
        CurrencyCode $base,
        CurrencyCode $display,
        bool $forceSingle,
        array $allowed,
        array $rates,
    ): void {
        if ($allowed === [] || ! in_array($base, $allowed, true) || ! in_array($display, $allowed, true)) {
            throw ValidationException::withMessages([
                'allowed_currencies' => 'Выберите базовую и отображаемую валюты среди доступных.',
            ]);
        }

        if ($forceSingle && ($base !== $display || $allowed !== [$base])) {
            throw ValidationException::withMessages([
                'force_single_currency' => 'При одномерном режиме должна быть выбрана только базовая валюта.',
            ]);
        }

        $required = $this->requiredCurrencies($organization);

        foreach ($required as $currency) {
            if (! in_array($currency, $allowed, true)) {
                throw ValidationException::withMessages([
                    'allowed_currencies' => 'Нельзя отключить валюту существующей услуги или финансового обязательства.',
                ]);
            }
        }

        $targets = [$base, $display];

        foreach ($required as $currency) {
            if (! in_array($currency, $targets, true)) {
                $targets[] = $currency;
            }
        }

        if (Schema::hasTable('financial_obligations')) {
            $organizationId = $organization instanceof Organization ? (int) $organization->getKey() : $organization;
            $obligationRows = DB::table('financial_obligations')
                ->where('organization_id', $organizationId)
                ->get(['base_currency', 'display_currency', 'payment_currency', 'settlement_currency']);

            foreach ($obligationRows as $row) {
                foreach (['base_currency', 'display_currency', 'payment_currency', 'settlement_currency'] as $column) {
                    $currency = $this->catalog->code($row->{$column});

                    if (! in_array($currency, $targets, true)) {
                        $targets[] = $currency;
                    }
                }
            }
        }

        foreach ($allowed as $source) {
            foreach ($targets as $target) {
                if ($source === $target) {
                    continue;
                }

                if (! array_key_exists($this->rateKey($source, $target), $rates)) {
                    throw ValidationException::withMessages([
                        'rates' => sprintf('Укажите курс %s → %s для сохранения настроек.', $source->value, $target->value),
                    ]);
                }
            }
        }
    }

    /** @return list<CurrencyCode> */
    public function requiredCurrencies(Organization|int $organization): array
    {
        $organizationId = $organization instanceof Organization ? (int) $organization->getKey() : $organization;
        $currencies = [];

        foreach (DB::table('services')
            ->where('organization_id', $organizationId)
            ->whereNotNull('price_currency')
            ->distinct()
            ->pluck('price_currency') as $currency) {
            $currencies[] = $this->catalog->code($currency);
        }

        foreach (DB::table('services')
            ->where('organization_id', $organizationId)
            ->whereNotNull('price_matrix')
            ->pluck('price_matrix') as $matrix) {
            $values = is_string($matrix) ? json_decode($matrix, true) : $matrix;
            if (! is_array($values)) {
                continue;
            }

            foreach (array_keys($values) as $currency) {
                try {
                    $currencies[] = $this->catalog->code($currency);
                } catch (InvalidArgumentException) {
                }
            }
        }

        if (Schema::hasTable('financial_obligations')) {
            $obligationRows = DB::table('financial_obligations')
                ->where('organization_id', $organizationId)
                ->get(['currency', 'base_currency', 'display_currency', 'payment_currency', 'settlement_currency']);

            foreach ($obligationRows as $row) {
                foreach (['currency', 'base_currency', 'display_currency', 'payment_currency', 'settlement_currency'] as $column) {
                    $currencies[] = $this->catalog->code($row->{$column});
                }
            }
        }

        $unique = [];

        foreach ($currencies as $currency) {
            if (! in_array($currency, $unique, true)) {
                $unique[] = $currency;
            }
        }

        usort($unique, static fn (CurrencyCode $left, CurrencyCode $right): int => $left->value <=> $right->value);

        return $unique;
    }

    public function rateKey(CurrencyCode $source, CurrencyCode $target): string
    {
        return $source->value.'>'.$target->value;
    }

    public function roundingMode(Organization|int $organization): FinancialRoundingMode
    {
        return FinancialRoundingMode::fromMixed($this->configuration($organization)->rounding_mode);
    }

    public function convert(
        Organization|int $organization,
        Money $source,
        CurrencyCode|string $targetCurrency,
        ?FinancialRoundingMode $roundingMode = null,
    ): MoneyConversionSnapshot {
        $organizationId = $organization instanceof Organization ? (int) $organization->getKey() : $organization;
        $target = $this->assertAllowed($organization, $targetCurrency);
        $roundingMode ??= $this->roundingMode($organization);

        if ($source->currency() === $target) {
            return new MoneyConversionSnapshot(
                sourceAmountMinor: $source->minorUnitsString(),
                sourceCurrency: $source->currency(),
                targetAmountMinor: $source->minorUnitsString(),
                targetCurrency: $target,
                rate: '1',
                rateId: null,
                rateVersion: null,
                effectiveAt: null,
                roundingMode: $roundingMode,
                sourceScale: $source->scale(),
                targetScale: $source->scale(),
            );
        }

        $rate = OrganizationExchangeRate::query()
            ->where('organization_id', $organizationId)
            ->where('source_currency', $source->currency()->value)
            ->where('target_currency', $target->value)
            ->first();

        if ($rate === null) {
            throw (new ModelNotFoundException)->setModel(OrganizationExchangeRate::class);
        }

        $rateValue = BigDecimal::of((string) $rate->getRawOriginal('rate'))
            ->strippedOfTrailingZeros()
            ->__toString();
        $converted = $source->convert($target, $rateValue, $roundingMode);

        return new MoneyConversionSnapshot(
            sourceAmountMinor: $source->minorUnitsString(),
            sourceCurrency: $source->currency(),
            targetAmountMinor: $converted->minorUnitsString(),
            targetCurrency: $target,
            rate: $rateValue,
            rateId: (int) $rate->getKey(),
            rateVersion: (int) $rate->version,
            effectiveAt: CarbonImmutable::parse($rate->effective_at->toIso8601String()),
            roundingMode: $roundingMode,
            sourceScale: $source->scale(),
            targetScale: $converted->scale(),
        );
    }

    public function convertTargetAmountToSource(
        Organization|int $organization,
        Money $targetAmount,
        CurrencyCode|string $sourceCurrency,
        ?FinancialRoundingMode $roundingMode = null,
    ): MoneyConversionSnapshot {
        $source = $this->assertAllowed($organization, $sourceCurrency);
        $target = $this->assertAllowed($organization, $targetAmount->currency());
        $roundingMode ??= $this->roundingMode($organization);

        if ($source === $target) {
            return new MoneyConversionSnapshot(
                sourceAmountMinor: $targetAmount->minorUnitsString(),
                sourceCurrency: $source,
                targetAmountMinor: $targetAmount->minorUnitsString(),
                targetCurrency: $target,
                rate: '1',
                rateId: null,
                rateVersion: null,
                effectiveAt: null,
                roundingMode: $roundingMode,
                sourceScale: $targetAmount->scale(),
                targetScale: $targetAmount->scale(),
            );
        }

        $organizationId = $organization instanceof Organization ? (int) $organization->getKey() : $organization;
        $rate = OrganizationExchangeRate::query()
            ->where('organization_id', $organizationId)
            ->where('source_currency', $source->value)
            ->where('target_currency', $target->value)
            ->first();

        if ($rate === null) {
            throw (new ModelNotFoundException)->setModel(OrganizationExchangeRate::class);
        }

        $rateValue = BigDecimal::of((string) $rate->getRawOriginal('rate'))
            ->strippedOfTrailingZeros()
            ->__toString();
        $sourceScale = $this->catalog->scale($source);
        $sourceMinor = BigDecimal::ofUnscaledValue($targetAmount->minorUnits(), $targetAmount->scale())
            ->dividedBy(BigDecimal::of($rateValue), $sourceScale + 18, FinancialRoundingMode::HalfUp->brick())
            ->toScale($sourceScale, $roundingMode->brick())
            ->getUnscaledValue();
        $sourceAmount = Money::ofMinor($sourceMinor->toString(), $source);

        return new MoneyConversionSnapshot(
            sourceAmountMinor: $sourceAmount->minorUnitsString(),
            sourceCurrency: $source,
            targetAmountMinor: $targetAmount->minorUnitsString(),
            targetCurrency: $target,
            rate: $rateValue,
            rateId: (int) $rate->getKey(),
            rateVersion: (int) $rate->version,
            effectiveAt: CarbonImmutable::parse($rate->effective_at->toIso8601String()),
            roundingMode: $roundingMode,
            sourceScale: $sourceAmount->scale(),
            targetScale: $targetAmount->scale(),
        );
    }
}
