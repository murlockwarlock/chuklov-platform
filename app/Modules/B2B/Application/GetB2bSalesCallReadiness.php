<?php

namespace App\Modules\B2B\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;

final class GetB2bSalesCallReadiness
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly ListEligibleB2bSalesCallSpecialists $eligibleSpecialists,
        private readonly GetB2bSalesCallDuration $duration,
    ) {}

    /** @return array{durationConfigured: bool, calendarConfigured: bool, automaticZoomConfigured: bool, manualLinkFallbackAvailable: bool} */
    public function handle(): array
    {
        $organizationId = $this->context->id();

        return [
            'durationConfigured' => $this->duration->handle() !== null,
            'calendarConfigured' => $this->eligibleSpecialists->exists(),
            'automaticZoomConfigured' => OrganizationCredential::query()
                ->where('organization_id', $organizationId)
                ->where('provider', 'zoom')
                ->where('credential_name', config('b2b.credential_name'))
                ->where('status', CredentialStatus::Active->value)
                ->exists(),
            'manualLinkFallbackAvailable' => true,
        ];
    }
}
