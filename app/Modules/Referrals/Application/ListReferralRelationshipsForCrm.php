<?php

namespace App\Modules\Referrals\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use Illuminate\Database\Eloquent\Builder;

final class ListReferralRelationshipsForCrm
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    /** @return Builder<ReferralRelationship> */
    public function query(User $actor): Builder
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);

        return ReferralRelationship::query()
            ->where('organization_id', $organization->getKey())
            ->with([
                'referrer:id,full_name',
                'referred:id,full_name',
                'referralIdentity:id,public_code',
                'referred.attribution',
            ])
            ->withCount('conversionObservations')
            ->withMax('conversionObservations', 'observed_at');
    }
}
