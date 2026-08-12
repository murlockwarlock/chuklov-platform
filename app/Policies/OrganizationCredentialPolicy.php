<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use LogicException;

class OrganizationCredentialPolicy
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        try {
            return $this->authorizer->allows($user, $this->context->organization(), OrganizationPermission::ManageCredentials);
        } catch (LogicException) {
            return false;
        }
    }

    public function view(User $user, OrganizationCredential $credential): bool
    {
        return $this->authorizer->allows($user, $credential->organization, OrganizationPermission::ManageCredentials);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, OrganizationCredential $credential): bool
    {
        return $this->view($user, $credential);
    }

    public function delete(User $user, OrganizationCredential $credential): bool
    {
        return $this->view($user, $credential);
    }
}
