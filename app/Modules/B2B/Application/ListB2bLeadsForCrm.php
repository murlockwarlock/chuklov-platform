<?php

namespace App\Modules\B2B\Application;

use App\Models\User;
use App\Modules\B2B\Domain\Models\B2bLead;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Database\Eloquent\Builder;

final class ListB2bLeadsForCrm
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    /** @return Builder<B2bLead> */
    public function query(User $actor): Builder
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewB2bLeads);

        return B2bLead::query()
            ->where('organization_id', $organization->getKey())
            ->with([
                'client:id,organization_id,full_name,email,phone,language,timezone',
                'salesCall:id,organization_id,lead_id,client_id,specialist_id,status,starts_at,ends_at,schedule_timezone,requested_timezone,meeting_mode,provider_name,provider_meeting_id,provider_meeting_uuid,provider_join_url,manual_meeting_url,provider_sync_status,provider_operation,provider_sync_version,event_version',
                'salesCall.specialist:id,organization_id,display_name',
            ]);
    }
}
