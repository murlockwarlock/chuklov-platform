<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrganizationChannelIdentity> */
class OrganizationChannelIdentityFactory extends Factory
{
    protected $model = OrganizationChannelIdentity::class;

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
        return $this->afterMaking(fn (OrganizationChannelIdentity $identity): OrganizationChannelIdentity => $identity->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function forUser(User $user): static
    {
        return $this->afterMaking(fn (OrganizationChannelIdentity $identity): OrganizationChannelIdentity => $identity->forceFill([
            'organization_id' => $user->memberships()->active()->value('organization_id'),
            'user_id' => $user->getKey(),
        ]));
    }

    public function verified(): static
    {
        return $this->state([
            'verification_status' => ChannelIdentityStatus::Verified->value,
            'verification_method' => 'test',
            'verified_at' => now(),
        ]);
    }
}
