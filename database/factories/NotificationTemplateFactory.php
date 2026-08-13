<?php

namespace Database\Factories;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationTemplate> */
class NotificationTemplateFactory extends Factory
{
    protected $model = NotificationTemplate::class;

    public function definition(): array
    {
        return [
            'template_key' => fake()->unique()->slug(),
            'name' => fake()->sentence(3),
            'locale' => 'en',
            'purpose' => ScenarioRulePurpose::Service->value,
            'is_active' => true,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (NotificationTemplate $template): NotificationTemplate => $template->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }
}
