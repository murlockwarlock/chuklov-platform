<?php

namespace Database\Factories;

use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Models\ScenarioDelivery;
use App\Modules\Scenarios\Domain\Models\ScenarioDeliveryAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScenarioDeliveryAttempt> */
class ScenarioDeliveryAttemptFactory extends Factory
{
    protected $model = ScenarioDeliveryAttempt::class;

    public function definition(): array
    {
        return [
            'attempt_number' => 1,
            'outcome' => NotificationDeliveryOutcome::Delivered->value,
            'error_code' => null,
            'provider_reference' => null,
            'attempted_at' => now(),
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (ScenarioDeliveryAttempt $attempt): ScenarioDeliveryAttempt => $attempt->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function forDelivery(ScenarioDelivery $delivery): static
    {
        return $this->afterMaking(fn (ScenarioDeliveryAttempt $attempt): ScenarioDeliveryAttempt => $attempt->forceFill([
            'organization_id' => $delivery->organization_id,
            'scenario_delivery_id' => $delivery->getKey(),
        ]));
    }
}
