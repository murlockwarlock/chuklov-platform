<?php

namespace Database\Factories;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScenarioEvent> */
class ScenarioEventFactory extends Factory
{
    protected $model = ScenarioEvent::class;

    public function definition(): array
    {
        return [
            'event_name' => ScenarioEventType::BookingCompleted->value,
            'aggregate_type' => 'booking',
            'aggregate_id' => (string) fake()->numberBetween(1, 100000),
            'occurred_at' => now(),
            'payload' => [
                'booking_id' => 1,
                'client_id' => 1,
                'status' => 'completed',
                'client_language' => 'en',
            ],
            'correlation_id' => fake()->uuid(),
            'causation_id' => null,
            'idempotency_key' => fake()->unique()->uuid(),
            'status' => ScenarioEventStatus::Pending->value,
            'available_at' => now(),
            'processing_started_at' => null,
            'processed_at' => null,
            'last_error_code' => null,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (ScenarioEvent $event): ScenarioEvent => $event->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }
}
