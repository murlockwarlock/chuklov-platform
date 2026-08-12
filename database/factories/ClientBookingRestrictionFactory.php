<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientBookingRestriction;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClientBookingRestriction> */
class ClientBookingRestrictionFactory extends Factory
{
    protected $model = ClientBookingRestriction::class;

    public function definition(): array
    {
        return [
            'reason' => 'Operational restriction',
            'blocked_at' => now(),
            'unblocked_at' => null,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (ClientBookingRestriction $restriction): ClientBookingRestriction => $restriction->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function forClient(Client $client): static
    {
        return $this->afterMaking(fn (ClientBookingRestriction $restriction): ClientBookingRestriction => $restriction->forceFill([
            'organization_id' => $client->organization_id,
            'client_id' => $client->getKey(),
        ]));
    }

    public function blockedBy(User $user): static
    {
        return $this->afterMaking(fn (ClientBookingRestriction $restriction): ClientBookingRestriction => $restriction->forceFill([
            'blocked_by_user_id' => $user->getKey(),
        ]));
    }
}
