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
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
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
        if ($service->price_minor === null) {
            return true;
        }

        try {
            return in_array($this->catalog->code((string) $service->price_currency), $this->allowedCurrencies($organization), true);
        } catch (InvalidArgumentException) {
            return false;
        }
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

        $converted = $source->convert($target, (string) $rate->getRawOriginal('rate'), $roundingMode);

        return new MoneyConversionSnapshot(
            sourceAmountMinor: $source->minorUnitsString(),
            sourceCurrency: $source->currency(),
            targetAmountMinor: $converted->minorUnitsString(),
            targetCurrency: $target,
            rate: (string) $rate->getRawOriginal('rate'),
            rateId: (int) $rate->getKey(),
            rateVersion: (int) $rate->version,
            effectiveAt: CarbonImmutable::parse($rate->effective_at->toIso8601String()),
            roundingMode: $roundingMode,
            sourceScale: $source->scale(),
            targetScale: $converted->scale(),
        );
    }
}
