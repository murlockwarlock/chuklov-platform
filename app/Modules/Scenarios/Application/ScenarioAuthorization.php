<?php

namespace App\Modules\Scenarios\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ScenarioAuthorization
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
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewScenarios);

        return $organization;
    }

    public function authorizeManage(User $actor): Organization
    {
        $organization = $this->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScenarios);

        return $organization;
    }

    public function assertOwned(Model $model): void
    {
        try {
            if ((int) $model->getAttribute('organization_id') !== $this->context->id()) {
                throw new AuthorizationException('The scenario record is outside the current organization.');
            }
        } catch (LogicException) {
            throw new AuthorizationException('The scenario organization is not resolved.');
        }
    }
}
