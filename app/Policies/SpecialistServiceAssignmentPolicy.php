<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use LogicException;

class SpecialistServiceAssignmentPolicy
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->allowsCurrent($user, OrganizationPermission::ViewScheduling);
    }

    public function view(User $user, SpecialistServiceAssignment $assignment): bool
    {
        return $this->authorizer->allows($user, $assignment->organization, OrganizationPermission::ViewScheduling);
    }

    public function create(User $user): bool
    {
        return $this->allowsCurrent($user, OrganizationPermission::ManageScheduling);
    }

    public function delete(User $user, SpecialistServiceAssignment $assignment): bool
    {
        return $this->authorizer->allows($user, $assignment->organization, OrganizationPermission::ManageScheduling);
    }

    private function allowsCurrent(User $user, OrganizationPermission $permission): bool
    {
        try {
            return $this->authorizer->allows($user, $this->context->organization(), $permission);
        } catch (LogicException) {
            return false;
        }
    }
}
