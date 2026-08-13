<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Models\Booking;
use Illuminate\Auth\Access\AuthorizationException;

final class BookingAuthorization
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    public function authorize(User|Client $actor, Booking $booking): void
    {
        $organization = $this->context->organization();

        if ((int) $booking->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The booking is outside the current organization.');
        }

        if ($actor instanceof User) {
            $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);

            return;
        }

        if ((int) $actor->organization_id !== $organization->getKey()
            || (int) $booking->client_id !== $actor->getKey()) {
            throw new AuthorizationException('The client may only manage its own booking.');
        }
    }
}
