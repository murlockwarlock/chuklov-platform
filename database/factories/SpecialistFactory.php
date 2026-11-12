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
            'profile_image_path' => null,
            'specialization' => null,
            'practices_since_year' => null,
            'qualification' => null,
            'bio' => null,
            'country' => null,
            'city' => null,
            'phone' => null,
            'email' => null,
            'telegram' => null,
            'phone_visible' => false,
            'email_visible' => false,
            'telegram_visible' => false,
            'is_active' => true,
            'notifications_enabled' => true,
            'staff_user_id' => null,
            'timezone' => null,
            'viewer_timezone' => null,
            'viewer_timezone_source' => 'organization',
            'viewer_timezone_suggestion' => null,
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
