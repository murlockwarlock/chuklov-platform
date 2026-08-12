<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Specialists\Domain\Models\Specialist;
use LogicException;

class SpecialistPolicy
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        try {
            return $this->authorizer->allows(
                $user,
                $this->context->organization(),
                OrganizationPermission::ViewSpecialists,
            );
        } catch (LogicException) {
            return false;
        }
    }

    public function view(User $user, Specialist $specialist): bool
    {
        return $this->authorizer->allows($user, $specialist->organization, OrganizationPermission::ViewSpecialists);
    }

    public function create(User $user): bool
    {
        return $this->viewAnyWithPermission($user, OrganizationPermission::ManageSpecialists);
    }

    public function update(User $user, Specialist $specialist): bool
    {
        return $this->authorizer->allows($user, $specialist->organization, OrganizationPermission::ManageSpecialists);
    }

    public function delete(User $user, Specialist $specialist): bool
    {
        return $this->update($user, $specialist);
    }

    private function viewAnyWithPermission(User $user, OrganizationPermission $permission): bool
    {
        try {
            return $this->authorizer->allows($user, $this->context->organization(), $permission);
        } catch (LogicException) {
            return false;
        }
    }
}
