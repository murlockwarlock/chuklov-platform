<?php

namespace App\Modules\Commerce\Application;

use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Services\Application\ServicePriceResolver;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;

final class ListGiftCertificateOfferings
{
    private const MAX_RESULTS = 50;

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly ServicePriceResolver $prices,
    ) {}

    /** @return array<int, string> */
    public function options(): array
    {
        $organization = $this->context->organization();

        return Service::query()
            ->where('organization_id', $organization->getKey())
            ->where('is_active', true)
            ->where('catalog_type', CatalogItemType::GiftCertificate->value)
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'organization_id', 'name', 'price_minor', 'price_currency', 'price_matrix'])
            ->mapWithKeys(function (Service $service): array {
                $price = $this->price($service);

                if (! $price instanceof Money) {
                    return [];
                }

                return [$service->getKey() => $this->label($service, $price)];
            })
            ->all();
    }

    public function find(int $serviceId): ?Service
    {
        $organization = $this->context->organization();

        return Service::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($serviceId)
            ->where('is_active', true)
            ->where('catalog_type', CatalogItemType::GiftCertificate->value)
            ->first();
    }

    public function price(Service $service): ?Money
    {
        $organization = $this->context->organization();
        $price = $this->prices->preferred($service, $organization);

        return $price instanceof Money && $price->isPositive() ? $price : null;
    }

    public function amountLabel(?int $serviceId): string
    {
        $service = $serviceId === null ? null : $this->find($serviceId);
        $price = $service instanceof Service ? $this->price($service) : null;

        return $price instanceof Money ? $price->toDecimalString() : '—';
    }

    public function currencyLabel(?int $serviceId): string
    {
        $service = $serviceId === null ? null : $this->find($serviceId);
        $price = $service instanceof Service ? $this->price($service) : null;

        return $price instanceof Money ? $price->currency()->value : '—';
    }

    private function label(Service $service, Money $price): string
    {
        return trim((string) $service->name).' · '.$price->toDecimalString().' '.$price->currency()->value;
    }
}
