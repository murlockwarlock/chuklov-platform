<?php

namespace Database\Factories;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Services\Domain\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'summary' => fake()->sentence(),
            'image_path' => null,
            'catalog_type' => 'service',
            'name_ru' => null,
            'name_en' => null,
            'description_ru' => null,
            'description_en' => null,
            'category' => 'general',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'formats' => ['office'],
            'price_minor' => null,
            'price_currency' => null,
            'payment_policy' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (Service $service): Service => $service->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }
}
