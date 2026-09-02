<?php

namespace Database\Factories;

use App\Modules\B2B\Domain\Enums\B2bLeadSource;
use App\Modules\B2B\Domain\Enums\B2bLeadStatus;
use App\Modules\B2B\Domain\Models\B2bLead;
use App\Modules\Broadcasts\Domain\Enums\B2bSpecialistAnswer;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<B2bLead> */
class B2bLeadFactory extends Factory
{
    protected $model = B2bLead::class;

    public function definition(): array
    {
        return [
            'b2b_specialist_answer' => B2bSpecialistAnswer::Yes->value,
            'source_channel' => B2bLeadSource::Portal->value,
            'idempotency_key' => fake()->unique()->uuid(),
            'request_hash' => hash('sha256', fake()->uuid()),
            'status' => B2bLeadStatus::New->value,
            'event_version' => 1,
            'submitted_at' => now(),
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (B2bLead $lead): B2bLead => $lead->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function forClient(Client $client): static
    {
        return $this->afterMaking(fn (B2bLead $lead): B2bLead => $lead->forceFill([
            'organization_id' => $client->organization_id,
            'client_id' => $client->getKey(),
        ]));
    }
}
