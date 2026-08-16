<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use LogicException;

class MedicalSessionPolicy
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->allowsCurrent($user, OrganizationPermission::ViewClients);
    }

    public function view(User $user, MedicalSession $session): bool
    {
        return $this->allows($user, $this->resolveOrganization($session), OrganizationPermission::ViewClients);
    }

    public function create(User $user): bool
    {
        return $this->allowsCurrent($user, OrganizationPermission::ManageClients);
    }

    public function update(User $user, MedicalSession $session): bool
    {
        return $this->allows($user, $this->resolveOrganization($session), OrganizationPermission::ManageClients);
    }

    public function delete(User $user, MedicalSession $session): bool
    {
        return $this->update($user, $session);
    }

    private function allows(User $user, Organization $organization, OrganizationPermission $permission): bool
    {
        return $this->authorizer->allows($user, $organization, $permission);
    }

    private function allowsCurrent(User $user, OrganizationPermission $permission): bool
    {
        try {
            return $this->allows($user, $this->context->organization(), $permission);
        } catch (LogicException) {
            return false;
        }
    }

    private function resolveOrganization(MedicalSession $session): Organization
    {
        try {
            if ((int) $session->organization_id === $this->context->id()) {
                return $this->context->organization();
            }
        } catch (LogicException) {
        }

        return $session->organization;
    }
}
