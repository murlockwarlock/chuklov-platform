<?php

namespace Database\Factories;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Specialist> */
class SpecialistFactory extends Factory
{
    protected $model = Specialist::class;

    public function definition(): array
    {
        return [
            'display_name' => fake()->name(),
            'is_active' => true,
            'staff_user_id' => null,
            'timezone' => null,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (Specialist $specialist): Specialist => $specialist->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
