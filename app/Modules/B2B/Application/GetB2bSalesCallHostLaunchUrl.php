<?php

namespace App\Modules\B2B\Application;

use App\Models\User;
use App\Modules\B2B\Domain\Contracts\VideoMeetingProvider;
use App\Modules\B2B\Domain\Enums\B2bSalesCallStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\B2B\Infrastructure\Video\VideoMeetingException;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class GetB2bSalesCallHostLaunchUrl
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly VideoMeetingProvider $provider,
    ) {}

    public function handle(User $actor, B2bSalesCall $salesCall): string
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageB2bLeads);

        if ((int) $salesCall->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The sales call is outside the current organization.');
        }

        $identity = $salesCall->providerIdentity();

        if ($salesCall->status !== B2bSalesCallStatus::Scheduled
            || $salesCall->meeting_mode !== VideoMeetingMode::Automatic
            || $identity === null) {
            throw ValidationException::withMessages([
                'provider' => 'A host link is available only for a scheduled Zoom sales call.',
            ]);
        }

        try {
            return $this->provider->obtainHostLaunchUrl($organization, $identity);
        } catch (VideoMeetingException) {
            throw ValidationException::withMessages([
                'provider' => 'The Zoom host link is temporarily unavailable. Retry from the CRM.',
            ]);
        }
    }
}
