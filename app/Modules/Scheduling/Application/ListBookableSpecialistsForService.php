<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

class ListBookableSpecialistsForService
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationFeatureGate $features,
    ) {}

    /** @return Collection<int, Specialist> */
    public function handle(int $serviceId): Collection
    {
        $organizationId = $this->context->id();
        $service = Service::query()
            ->where('organization_id', $organizationId)
            ->find($serviceId);

        if (! $service instanceof Service) {
            throw new AuthorizationException('The service is outside the current organization.');
        }

        if (! $this->features->isEnabled($this->context->organization(), OrganizationFeature::ServiceCatalog)) {
            return new Collection;
        }

        if (! $service->is_active || $service->catalogItemType() !== CatalogItemType::Service) {
            return new Collection;
        }

        $assignedSpecialists = SpecialistServiceAssignment::query()
            ->where('organization_id', $organizationId)
            ->where('service_id', $service->getKey())
            ->select('specialist_id');

        return Specialist::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereIn('id', $assignedSpecialists)
            ->orderBy('display_name')
            ->get(['id', 'display_name', 'timezone']);
    }
}
