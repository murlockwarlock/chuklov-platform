<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use LogicException;

class ScheduleExceptionPolicy
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->allowsCurrent($user, OrganizationPermission::ViewScheduling);
    }

    public function view(User $user, ScheduleException $exception): bool
    {
        return $this->authorizer->allows($user, $exception->organization, OrganizationPermission::ViewScheduling);
    }

    public function create(User $user): bool
    {
        return $this->allowsCurrent($user, OrganizationPermission::ManageScheduling);
    }

    public function update(User $user, ScheduleException $exception): bool
    {
        return $this->authorizer->allows($user, $exception->organization, OrganizationPermission::ManageScheduling);
    }

    public function delete(User $user, ScheduleException $exception): bool
    {
        return $this->update($user, $exception);
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
