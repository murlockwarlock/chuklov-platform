<?php

namespace App\Modules\Broadcasts\Application;

use App\Models\User;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;

final readonly class BroadcastAuthorization
{
    public function __construct(private OrganizationContext $context, private OrganizationAuthorizer $authorizer) {}

    public function manage(User $actor): Organization
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScenarios);

        return $organization;
    }

    public function creatorCanExecute(BroadcastCampaign $campaign): bool
    {
        if ($campaign->created_by_user_id === null) {
            return false;
        }

        $creator = User::query()->whereKey($campaign->created_by_user_id)->first();
        if ($creator === null) {
            return false;
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $campaign->organization_id)
            ->where('user_id', $creator->getKey())
            ->active()
            ->first();

        return $membership instanceof OrganizationMembership
            && $membership->role->allows(OrganizationPermission::ManageScenarios);
    }
}
