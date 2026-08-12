<?php

namespace Database\Factories;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\SpecialistWorkingHour;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SpecialistWorkingHour> */
class SpecialistWorkingHourFactory extends Factory
{
    protected $model = SpecialistWorkingHour::class;

    public function definition(): array
    {
        return [
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '17:00',
            'is_active' => true,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (SpecialistWorkingHour $hour): SpecialistWorkingHour => $hour->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function forSpecialist(Specialist $specialist): static
    {
        return $this->afterMaking(fn (SpecialistWorkingHour $hour): SpecialistWorkingHour => $hour->forceFill([
            'organization_id' => $specialist->organization_id,
            'specialist_id' => $specialist->getKey(),
        ]));
    }
}
