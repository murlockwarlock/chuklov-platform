<?php

namespace App\Modules\Broadcasts\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;

final readonly class BroadcastAuthorization
{
    public function __construct(private OrganizationContext $context, private OrganizationAuthorizer $authorizer) {}

    public function manage(User $actor): Organization
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScenarios);

        return $organization;
    }
}
