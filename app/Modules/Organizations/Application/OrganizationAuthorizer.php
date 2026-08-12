<?php

namespace App\Modules\Organizations\Application;

use App\Models\User;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

class OrganizationAuthorizer
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function authorize(
        User $user,
        Organization $organization,
        OrganizationPermission $permission,
    ): OrganizationMembership {
        $membership = $user->membershipFor($organization);

        if (! $this->isCurrent($organization)
            || ! $membership instanceof OrganizationMembership
            || ! $membership->role->allows($permission)) {
            throw new AuthorizationException('The user is not authorized for this organization action.');
        }

        return $membership;
    }

    public function allows(
        User $user,
        Organization $organization,
        OrganizationPermission $permission,
    ): bool {
        $membership = $user->membershipFor($organization);

        return $this->isCurrent($organization)
            && $membership instanceof OrganizationMembership
            && $membership->role->allows($permission);
    }

    private function isCurrent(Organization $organization): bool
    {
        try {
            return $this->context->id() === $organization->getKey();
        } catch (LogicException) {
            return false;
        }
    }
}
