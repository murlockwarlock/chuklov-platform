<?php

namespace Database\Factories;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScenarioAction> */
class ScenarioActionFactory extends Factory
{
    protected $model = ScenarioAction::class;

    public function definition(): array
    {
        return [
            'recipient_type' => 'client',
            'trigger_event' => ScenarioEventType::BookingCompleted->value,
            'rule_version' => 1,
            'purpose' => ScenarioRulePurpose::Service->value,
            'channel_priority' => ['telegram'],
            'render_context' => ['client' => ['full_name' => 'Client']],
            'materialization_key' => fake()->unique()->uuid(),
            'scheduled_for' => now(),
            'status' => ScenarioActionStatus::Scheduled->value,
            'attempt_count' => 0,
            'processing_started_at' => null,
            'delivered_at' => null,
            'suppressed_at' => null,
            'terminal_reason' => null,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (ScenarioAction $action): ScenarioAction => $action->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function forEvent(ScenarioEvent $event): static
    {
        return $this->afterMaking(fn (ScenarioAction $action): ScenarioAction => $action->forceFill([
            'organization_id' => $event->organization_id,
            'scenario_event_id' => $event->getKey(),
        ]));
    }

    public function forRule(ScenarioRule $rule): static
    {
        return $this->afterMaking(fn (ScenarioAction $action): ScenarioAction => $action->forceFill([
            'organization_id' => $rule->organization_id,
            'scenario_rule_id' => $rule->getKey(),
            'rule_version' => $rule->version,
        ]));
    }

    public function forTemplate(NotificationTemplateVersion $version): static
    {
        return $this->afterMaking(fn (ScenarioAction $action): ScenarioAction => $action->forceFill([
            'organization_id' => $version->organization_id,
            'template_version_id' => $version->getKey(),
        ]));
    }

    public function forClient(Client $client): static
    {
        return $this->afterMaking(fn (ScenarioAction $action): ScenarioAction => $action->forceFill([
            'organization_id' => $client->organization_id,
            'client_id' => $client->getKey(),
        ]));
    }
}
