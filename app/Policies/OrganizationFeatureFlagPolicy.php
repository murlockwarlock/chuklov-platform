<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use LogicException;

class OrganizationFeatureFlagPolicy
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        try {
            return $this->authorizer->allows($user, $this->context->organization(), OrganizationPermission::ManageFeatures);
        } catch (LogicException) {
            return false;
        }
    }

    public function view(User $user, OrganizationFeatureFlag $flag): bool
    {
        return $this->authorizer->allows($user, $flag->organization, OrganizationPermission::ManageFeatures);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, OrganizationFeatureFlag $flag): bool
    {
        return $this->view($user, $flag);
    }

    public function delete(User $user, OrganizationFeatureFlag $flag): bool
    {
        return $this->view($user, $flag);
    }
}
