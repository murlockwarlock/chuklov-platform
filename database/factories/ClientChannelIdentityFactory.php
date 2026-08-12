<?php

namespace Database\Factories;

use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientChannelIdentity>
 */
class ClientChannelIdentityFactory extends Factory
{
    protected $model = ClientChannelIdentity::class;

    public function definition(): array
    {
        return [
            'channel' => 'telegram',
            'external_id' => fake()->unique()->numerify('##########'),
            'verification_status' => ChannelIdentityStatus::Unverified->value,
            'verification_method' => null,
            'verified_at' => null,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (ClientChannelIdentity $identity): ClientChannelIdentity => $identity->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function forClient(Client $client): static
    {
        return $this->afterMaking(fn (ClientChannelIdentity $identity): ClientChannelIdentity => $identity->forceFill([
            'organization_id' => $client->organization_id,
            'client_id' => $client->getKey(),
        ]));
    }
}
