<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\Booking;
use LogicException;

class BookingPolicy
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->allowsCurrent($user, OrganizationPermission::ViewScheduling);
    }

    public function view(User $user, Booking $booking): bool
    {
        $organization = $this->resolveBookingOrganization($booking);

        return $this->authorizer->allows($user, $organization, OrganizationPermission::ViewScheduling);
    }

    private function allowsCurrent(User $user, OrganizationPermission $permission): bool
    {
        try {
            return $this->authorizer->allows($user, $this->context->organization(), $permission);
        } catch (LogicException) {
            return false;
        }
    }

    private function resolveBookingOrganization(Booking $booking): Organization
    {
        try {
            if ((int) $booking->organization_id === $this->context->id()) {
                return $this->context->organization();
            }
        } catch (LogicException) {
            // Context not bound
        }

        return $booking->organization;
    }
}
