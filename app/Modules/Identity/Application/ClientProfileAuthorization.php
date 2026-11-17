<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;

final class ClientProfileAuthorization
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    public function authorizeManageAvatar(User|Client $actor, Client $client): Organization
    {
        $organization = $this->context->organization();
        $this->assertClientOwned($client, $organization);
        if ($actor instanceof Client) {
            $this->assertClientOwned($actor, $organization);
            if ((int) $actor->getKey() !== (int) $client->getKey()) {
                throw new AuthorizationException('Клиент может менять только свой аватар.');
            }

            return $organization;
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);

        return $organization;
    }

    private function assertClientOwned(Client $client, Organization $organization): void
    {
        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }
    }
}
