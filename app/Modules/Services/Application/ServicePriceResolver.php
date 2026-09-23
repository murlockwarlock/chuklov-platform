<?php

namespace App\Modules\Services\Application;

use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Services\Domain\ValueObjects\ServicePriceMatrix;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ServicePriceResolver
{
    public function __construct(private readonly CurrencyConfigurationService $currencies) {}

    public function resolve(Service $service, CurrencyCode|string $currency, Organization|int|null $organization = null): ?Money
    {
        try {
            $target = app(CurrencyCatalog::class)->code($currency);
        } catch (InvalidArgumentException) {
            return null;
        }

        $matrix = ServicePriceMatrix::fromMinor($service->getAttribute('price_matrix'));
        $fixed = $matrix->minorUnits()[$target->value] ?? null;
        if ($fixed !== null) {
            return Money::ofMinor($fixed, $target);
        }

        $minor = $service->getAttribute('price_minor');
        $sourceCurrency = $service->getAttribute('price_currency');
        if ($minor === null || ! is_string($sourceCurrency) || trim($sourceCurrency) === '') {
            return null;
        }

        try {
            $source = Money::ofMinor((int) $minor, $sourceCurrency);
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($source->currency() === $target) {
            return $source;
        }

        if ($organization === null) {
            return null;
        }

        try {
            return Money::ofMinor(
                $this->currencies->convert($organization, $source, $target)->targetAmountMinor,
                $target,
            );
        } catch (InvalidArgumentException|ModelNotFoundException|ValidationException) {
            return null;
        }
    }

    /** @return list<CurrencyCode> */
    public function candidateCurrencies(Service $service, Organization|int|null $organization = null): array
    {
        $values = [];

        if ($organization !== null) {
            try {
                $configuration = $this->currencies->configuration($organization);
                $values[] = $configuration->display_currency;
                $values[] = $configuration->base_currency;
            } catch (ModelNotFoundException) {
            }
        }

        $priceCurrency = $service->getAttribute('price_currency');
        if (is_string($priceCurrency) && trim($priceCurrency) !== '') {
            try {
                $values[] = app(CurrencyCatalog::class)->code($priceCurrency);
            } catch (InvalidArgumentException) {
            }
        }

        foreach (array_keys(ServicePriceMatrix::fromMinor($service->getAttribute('price_matrix'))->minorUnits()) as $currency) {
            try {
                $values[] = app(CurrencyCatalog::class)->code($currency);
            } catch (InvalidArgumentException) {
            }
        }

        $unique = [];
        foreach ($values as $currency) {
            if (! in_array($currency, $unique, true)) {
                $unique[] = $currency;
            }
        }

        return $unique;
    }

    public function preferred(Service $service, Organization|int|null $organization = null): ?Money
    {
        foreach ($this->candidateCurrencies($service, $organization) as $currency) {
            $price = $this->resolve($service, $currency, $organization);
            if ($price instanceof Money) {
                return $price;
            }
        }

        return null;
    }
}
