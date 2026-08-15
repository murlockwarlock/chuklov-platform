<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use LogicException;

class ClientPolicy
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationContext $context,
        private readonly OrganizationFeatureGate $features,
    ) {}

    public function viewAny(User $user): bool
    {
        $organization = $this->currentOrganization();

        return $organization instanceof Organization
            && $this->allows($user, $organization, OrganizationPermission::ViewClients);
    }

    public function view(User $user, Client $client): bool
    {
        $organization = $this->resolveClientOrganization($client);

        return $this->allows($user, $organization, OrganizationPermission::ViewClients);
    }

    public function create(User $user): bool
    {
        $organization = $this->currentOrganization();

        return $organization instanceof Organization
            && $this->allows($user, $organization, OrganizationPermission::ManageClients);
    }

    public function update(User $user, Client $client): bool
    {
        $organization = $this->resolveClientOrganization($client);

        return $this->allows($user, $organization, OrganizationPermission::ManageClients);
    }

    public function delete(User $user, Client $client): bool
    {
        return $this->update($user, $client);
    }

    private function allows(User $user, Organization $organization, OrganizationPermission $permission): bool
    {
        return $this->features->isEnabled($organization, OrganizationFeature::ClientRecords)
            && $this->authorizer->allows($user, $organization, $permission);
    }

    private function currentOrganization(): ?Organization
    {
        try {
            return $this->context->organization();
        } catch (LogicException) {
            return null;
        }
    }

    private function resolveClientOrganization(Client $client): Organization
    {
        try {
            if ((int) $client->organization_id === $this->context->id()) {
                return $this->context->organization();
            }
        } catch (LogicException) {
            // Context not bound
        }

        return $client->organization;
    }
}
