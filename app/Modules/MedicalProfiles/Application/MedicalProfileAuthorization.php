<?php

namespace App\Modules\MedicalProfiles\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

final class MedicalProfileAuthorization
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    public function organization(): Organization
    {
        return $this->context->organization();
    }

    public function authorizeView(User $actor, Client $client): Organization
    {
        $organization = $this->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        $this->assertClientOwned($client, $organization);

        return $organization;
    }

    public function authorizeManage(User $actor, Client $client): Organization
    {
        $organization = $this->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);
        $this->assertClientOwned($client, $organization);

        return $organization;
    }

    public function allowsView(User $actor, Client $client): bool
    {
        try {
            $organization = $this->organization();

            return (int) $client->organization_id === (int) $organization->getKey()
                && $this->authorizer->allows($actor, $organization, OrganizationPermission::ViewClients);
        } catch (LogicException) {
            return false;
        }
    }

    public function allowsManage(User $actor, Client $client): bool
    {
        try {
            $organization = $this->organization();

            return (int) $client->organization_id === (int) $organization->getKey()
                && $this->authorizer->allows($actor, $organization, OrganizationPermission::ManageClients);
        } catch (LogicException) {
            return false;
        }
    }

    public function assertClientOwned(Client $client, ?Organization $organization = null): void
    {
        $orgId = $organization !== null ? (int) $organization->getKey() : $this->context->id();

        if ((int) $client->organization_id !== $orgId) {
            throw new AuthorizationException('The client is outside the current organization.');
        }
    }
}
