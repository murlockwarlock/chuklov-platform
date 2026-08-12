<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use LogicException;

class ClientChannelIdentityPolicy
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationContext $context,
        private readonly OrganizationFeatureGate $features,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->allowsCurrent($user, OrganizationPermission::ViewClients);
    }

    public function view(User $user, ClientChannelIdentity $identity): bool
    {
        return $this->allows($user, $identity->client->organization, OrganizationPermission::ViewClients);
    }

    public function create(User $user): bool
    {
        return $this->allowsCurrent($user, OrganizationPermission::ManageClients);
    }

    public function update(User $user, ClientChannelIdentity $identity): bool
    {
        return $this->allows($user, $identity->client->organization, OrganizationPermission::ManageClients);
    }

    public function delete(User $user, ClientChannelIdentity $identity): bool
    {
        return $this->update($user, $identity);
    }

    private function allowsCurrent(User $user, OrganizationPermission $permission): bool
    {
        try {
            return $this->allows($user, $this->context->organization(), $permission);
        } catch (LogicException) {
            return false;
        }
    }

    private function allows(User $user, Organization $organization, OrganizationPermission $permission): bool
    {
        return $this->features->isEnabled($organization, OrganizationFeature::ClientRecords)
            && $this->authorizer->allows($user, $organization, $permission);
    }
}
