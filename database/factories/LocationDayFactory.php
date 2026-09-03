<?php

namespace Database\Factories;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\LocationDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LocationDay> */
class LocationDayFactory extends Factory
{
    protected $model = LocationDay::class;

    public function definition(): array
    {
        return [
            'area_name' => 'Bang Tao',
            'weekday' => 5,
            'specific_date' => null,
            'start_time' => '10:00',
            'end_time' => '18:00',
            'timezone' => 'Asia/Bangkok',
            'is_active' => true,
            'notes' => null,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (LocationDay $day): LocationDay => $day->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function forDate(string $date): static
    {
        return $this->state([
            'weekday' => null,
            'specific_date' => $date,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
