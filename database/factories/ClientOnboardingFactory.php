<?php

namespace Database\Factories;

use App\Modules\ClientPortal\Domain\Enums\ClientOnboardingStage;
use App\Modules\ClientPortal\Domain\Models\ClientOnboarding;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientOnboarding>
 */
class ClientOnboardingFactory extends Factory
{
    protected $model = ClientOnboarding::class;

    public function definition(): array
    {
        return [
            'flow_version' => 'm2-v1',
            'current_stage' => ClientOnboardingStage::Contacts->value,
            'data' => [],
            'completed_at' => null,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (ClientOnboarding $onboarding): ClientOnboarding => $onboarding->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function forClient(Client $client): static
    {
        return $this->afterMaking(fn (ClientOnboarding $onboarding): ClientOnboarding => $onboarding->forceFill([
            'organization_id' => $client->organization_id,
            'client_id' => $client->getKey(),
        ]));
    }
}
