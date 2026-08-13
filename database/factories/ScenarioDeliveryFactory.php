<?php

namespace Database\Factories;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\ScenarioDeliveryStatus;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScenarioDelivery> */
class ScenarioDeliveryFactory extends Factory
{
    protected $model = ScenarioDelivery::class;

    public function definition(): array
    {
        return [
            'channel' => 'telegram',
            'priority' => 1,
            'status' => ScenarioDeliveryStatus::Pending->value,
            'idempotency_key' => fake()->unique()->uuid(),
            'attempt_count' => 0,
            'next_attempt_at' => null,
            'processing_started_at' => null,
            'delivered_at' => null,
            'last_error_code' => null,
            'terminal_reason' => null,
            'provider_reference' => null,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (ScenarioDelivery $delivery): ScenarioDelivery => $delivery->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function forAction(ScenarioAction $action): static
    {
        return $this->afterMaking(fn (ScenarioDelivery $delivery): ScenarioDelivery => $delivery->forceFill([
            'organization_id' => $action->organization_id,
            'scenario_action_id' => $action->getKey(),
        ]));
    }
}
