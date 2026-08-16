<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

final class MedicalSessionAuthorization
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    public function organization(): Organization
    {
        return $this->context->organization();
    }

    public function authorizeManage(User $actor, Client $client): Organization
    {
        $organization = $this->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);
        $this->assertClientOwned($client, $organization);

        return $organization;
    }

    public function authorizeView(User $actor, MedicalSession $session): Organization
    {
        $organization = $this->organization();
        $this->assertSessionOwned($session, $organization);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);

        return $organization;
    }

    public function authorizeManageSession(User $actor, MedicalSession $session): Organization
    {
        $organization = $this->organization();
        $this->assertSessionOwned($session, $organization);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);

        return $organization;
    }

    public function assertClientOwned(Client $client, ?Organization $organization = null): void
    {
        $orgId = $organization !== null ? (int) $organization->getKey() : $this->context->id();

        if ((int) $client->organization_id !== $orgId) {
            throw new AuthorizationException('The client is outside the current organization.');
        }
    }

    public function assertSessionOwned(MedicalSession $session, ?Organization $organization = null): void
    {
        $orgId = $organization !== null ? (int) $organization->getKey() : $this->context->id();

        if ((int) $session->organization_id !== $orgId) {
            throw new AuthorizationException('The medical session is outside the current organization.');
        }
    }

    public function allowsView(User $actor, MedicalSession $session): bool
    {
        try {
            $organization = $this->organization();

            return (int) $session->organization_id === (int) $organization->getKey()
                && $this->authorizer->allows($actor, $organization, OrganizationPermission::ViewClients);
        } catch (LogicException) {
            return false;
        }
    }

    public function allowsManageSession(User $actor, MedicalSession $session): bool
    {
        try {
            $organization = $this->organization();

            return (int) $session->organization_id === (int) $organization->getKey()
                && $this->authorizer->allows($actor, $organization, OrganizationPermission::ManageClients);
        } catch (LogicException) {
            return false;
        }
    }
}
