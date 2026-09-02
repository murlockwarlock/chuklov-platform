<?php

namespace App\Modules\B2B\Application;

final class GetB2bSalesCallReadiness
{
    public function __construct(
        private readonly ListEligibleB2bSalesCallSpecialists $eligibleSpecialists,
        private readonly GetB2bSalesCallDuration $duration,
        private readonly GetB2bZoomConfiguration $zoomConfiguration,
    ) {}

    /** @return array{durationConfigured: bool, calendarConfigured: bool, automaticZoomConfigured: bool, manualLinkFallbackAvailable: bool} */
    public function handle(): array
    {
        return [
            'durationConfigured' => $this->duration->handle() !== null,
            'calendarConfigured' => $this->eligibleSpecialists->exists(),
            'automaticZoomConfigured' => $this->zoomConfiguration->handle()['configured'],
            'manualLinkFallbackAvailable' => true,
        ];
    }
}
