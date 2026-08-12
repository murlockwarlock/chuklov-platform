<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationMembership>
 */
class OrganizationMembershipFactory extends Factory
{
    protected $model = OrganizationMembership::class;

    public function definition(): array
    {
        return [
            'role' => OrganizationRole::Staff->value,
            'is_active' => true,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (OrganizationMembership $membership): OrganizationMembership => $membership->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function forUser(User $user): static
    {
        return $this->afterMaking(fn (OrganizationMembership $membership): OrganizationMembership => $membership->forceFill([
            'user_id' => $user->getKey(),
        ]));
    }
}
