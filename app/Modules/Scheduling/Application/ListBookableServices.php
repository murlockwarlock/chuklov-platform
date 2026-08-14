<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Database\Eloquent\Collection;

class ListBookableServices
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationFeatureGate $features,
    ) {}

    /** @return Collection<int, Service> */
    public function handle(): Collection
    {
        $organizationId = $this->context->id();

        if (! $this->features->isEnabled($this->context->organization(), OrganizationFeature::ServiceCatalog)) {
            return new Collection;
        }

        $activeSpecialists = Specialist::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->select('id');
        $assignedServices = SpecialistServiceAssignment::query()
            ->where('organization_id', $organizationId)
            ->whereIn('specialist_id', $activeSpecialists)
            ->select('service_id');

        return Service::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where('catalog_type', CatalogItemType::Service->value)
            ->whereIn('id', $assignedServices)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'summary',
                'name_ru',
                'name_en',
                'description_ru',
                'description_en',
                'duration_minutes',
                'price_minor',
                'price_currency',
                'formats',
            ]);
    }
}
