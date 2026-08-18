<?php

namespace App\Modules\Organizations\Application;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Application\GetBookingLeadTime;
use App\Modules\Scheduling\Application\SpecialistServiceAssignmentEligibility;
use LogicException;

class OrganizationContext
{
    private ?Organization $organization = null;

    private ?string $defaultTimezone = null;

    public function set(Organization $organization): void
    {
        $this->organization = $organization;
        $this->defaultTimezone = null;
        OrganizationFeatureGate::invalidate();
        GetBookingLeadTime::invalidate();
        SpecialistServiceAssignmentEligibility::invalidate();
    }

    public function organization(): Organization
    {
        return $this->organization ?? throw new LogicException('Organization context is not resolved.');
    }

    public function id(): int
    {
        return $this->organization()->getKey();
    }

    public function defaultTimezone(): string
    {
        return $this->defaultTimezone ??= $this->organization()->defaultTimezone();
    }

    public function invalidateDefaultTimezone(): void
    {
        $this->defaultTimezone = null;
    }
}
