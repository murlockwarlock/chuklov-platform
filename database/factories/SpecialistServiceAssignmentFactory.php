<?php

namespace Database\Factories;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SpecialistServiceAssignment> */
class SpecialistServiceAssignmentFactory extends Factory
{
    protected $model = SpecialistServiceAssignment::class;

    public function definition(): array
    {
        return [];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (SpecialistServiceAssignment $assignment): SpecialistServiceAssignment => $assignment->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function forSpecialist(Specialist $specialist): static
    {
        return $this->afterMaking(fn (SpecialistServiceAssignment $assignment): SpecialistServiceAssignment => $assignment->forceFill([
            'organization_id' => $specialist->organization_id,
            'specialist_id' => $specialist->getKey(),
        ]));
    }

    public function forService(Service $service): static
    {
        return $this->afterMaking(fn (SpecialistServiceAssignment $assignment): SpecialistServiceAssignment => $assignment->forceFill([
            'organization_id' => $service->organization_id,
            'service_id' => $service->getKey(),
        ]));
    }
}
