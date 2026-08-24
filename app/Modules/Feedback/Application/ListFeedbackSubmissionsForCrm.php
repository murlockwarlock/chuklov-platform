<?php

namespace App\Modules\Feedback\Application;

use App\Models\User;
use App\Modules\Feedback\Domain\Models\FeedbackSubmission;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Database\Eloquent\Builder;

final class ListFeedbackSubmissionsForCrm
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    /** @return Builder<FeedbackSubmission> */
    public function query(User $actor): Builder
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);

        return FeedbackSubmission::query()
            ->where('organization_id', $organization->getKey())
            ->with('client:id,full_name');
    }
}
