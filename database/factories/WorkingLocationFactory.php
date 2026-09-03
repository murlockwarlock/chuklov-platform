<?php

namespace Database\Factories;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\WorkingLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkingLocation> */
class WorkingLocationFactory extends Factory
{
    protected $model = WorkingLocation::class;

    public function definition(): array
    {
        return [
            'name' => fake()->city().' office',
            'address' => fake()->address(),
            'timezone' => 'UTC',
            'latitude' => null,
            'longitude' => null,
            'map_url' => null,
            'is_active' => true,
            'is_default_office' => false,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (WorkingLocation $location): WorkingLocation => $location->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function defaultOffice(): static
    {
        return $this->state(['is_default_office' => true]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
