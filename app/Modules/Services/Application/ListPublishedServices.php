<?php

namespace App\Modules\Services\Application;

use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Services\Domain\Models\Service;
use Illuminate\Database\Eloquent\Collection;

class ListPublishedServices
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationFeatureGate $features,
        private readonly CurrencyConfigurationService $currencies,
    ) {}

    /** @return Collection<int, Service> */
    public function handle(): Collection
    {
        $organization = $this->context->organization();

        if (! $this->features->isEnabled($organization, OrganizationFeature::ServiceCatalog)) {
            return new Collection;
        }

        return Service::query()
            ->where('organization_id', $organization->getKey())
            ->where('is_active', true)
            ->where('catalog_type', 'service')
            ->orderBy('name')
            ->get()
            ->filter(fn (Service $service): bool => $this->currencies->isServicePriceAvailable($organization, $service))
            ->values();
    }
}
