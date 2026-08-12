<?php

namespace Database\Factories;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelLinkToken;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientChannelLinkToken>
 */
class ClientChannelLinkTokenFactory extends Factory
{
    protected $model = ClientChannelLinkToken::class;

    public function definition(): array
    {
        return [
            'channel' => 'telegram',
            'flow' => 'portal.telegram.connect',
            'token_hash' => hash('sha256', fake()->unique()->sha256()),
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => null,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (ClientChannelLinkToken $token): ClientChannelLinkToken => $token->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function forClient(Client $client): static
    {
        return $this->afterMaking(fn (ClientChannelLinkToken $token): ClientChannelLinkToken => $token->forceFill([
            'organization_id' => $client->organization_id,
            'client_id' => $client->getKey(),
        ]));
    }
}
