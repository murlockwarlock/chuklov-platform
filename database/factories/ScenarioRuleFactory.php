<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\ScenarioDelayUnit;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScenarioRule> */
class ScenarioRuleFactory extends Factory
{
    protected $model = ScenarioRule::class;

    public function definition(): array
    {
        return [
            'rule_key' => fake()->unique()->slug(),
            'name' => fake()->sentence(3),
            'trigger_event' => ScenarioEventType::BookingCompleted->value,
            'is_enabled' => true,
            'delay_value' => 24,
            'delay_unit' => ScenarioDelayUnit::Hours->value,
            'purpose' => ScenarioRulePurpose::Service->value,
            'conditions' => [],
            'recipient_strategy' => ['type' => 'client'],
            'channel_priority' => ['telegram'],
            'max_occurrences' => 1,
            'repeat_interval_value' => null,
            'repeat_interval_unit' => null,
            'version' => 1,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (ScenarioRule $rule): ScenarioRule => $rule->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function usingTemplate(NotificationTemplateVersion $version): static
    {
        return $this->afterMaking(fn (ScenarioRule $rule): ScenarioRule => $rule->forceFill([
            'organization_id' => $version->organization_id,
            'template_version_id' => $version->getKey(),
        ]));
    }

    public function createdBy(User $user): static
    {
        return $this->afterMaking(fn (ScenarioRule $rule): ScenarioRule => $rule->forceFill([
            'created_by_user_id' => $user->getKey(),
            'updated_by_user_id' => $user->getKey(),
        ]));
    }
}
