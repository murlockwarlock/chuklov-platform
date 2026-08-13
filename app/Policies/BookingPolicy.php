<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
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
        return $this->authorizer->allows($user, $booking->organization, OrganizationPermission::ViewScheduling);
    }

    private function allowsCurrent(User $user, OrganizationPermission $permission): bool
    {
        try {
            return $this->authorizer->allows($user, $this->context->organization(), $permission);
        } catch (LogicException) {
            return false;
        }
    }
}
