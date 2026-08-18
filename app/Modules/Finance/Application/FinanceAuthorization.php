<?php

namespace App\Modules\Finance\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class FinanceAuthorization
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    public function organization(): Organization
    {
        return $this->context->organization();
    }

    public function authorizeView(User $actor): Organization
    {
        $organization = $this->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewFinance);

        return $organization;
    }

    public function authorizeManage(User $actor): Organization
    {
        $organization = $this->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageFinance);

        return $organization;
    }

    public function assertClientOwned(Client $client): void
    {
        if ((int) $client->organization_id !== (int) $this->organization()->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }
    }

    public function allowsView(User $actor): bool
    {
        try {
            return $this->authorizer->allows($actor, $this->organization(), OrganizationPermission::ViewFinance);
        } catch (LogicException) {
            return false;
        }
    }

    public function allowsManage(User $actor): bool
    {
        try {
            return $this->authorizer->allows($actor, $this->organization(), OrganizationPermission::ManageFinance);
        } catch (LogicException) {
            return false;
        }
    }

    public function assertOwned(Model $model): void
    {
        try {
            if ((int) $model->getAttribute('organization_id') !== $this->context->id()) {
                throw new AuthorizationException('The finance record is outside the current organization.');
            }
        } catch (LogicException) {
            throw new AuthorizationException('The finance organization is not resolved.');
        }
    }
}
