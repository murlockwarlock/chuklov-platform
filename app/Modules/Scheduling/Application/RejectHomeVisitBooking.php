<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Enums\BookingEventType;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectHomeVisitBooking
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Booking $booking, string $reason): Booking
    {
        $organization = $this->context->organization();

        if ((int) $booking->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The booking is outside the current organization.');
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'A rejection reason is required.']);
        }

        return DB::transaction(function () use ($actor, $booking, $organization, $reason): Booking {
            $lockedBooking = Booking::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBooking->status !== BookingStatus::PendingReview
                || $lockedBooking->visit_format !== VisitFormat::HomeVisit) {
                throw ValidationException::withMessages([
                    'booking' => 'Only pending home-visit requests can be rejected.',
                ]);
            }

            $oldValues = $this->bookingSnapshot($lockedBooking);
            $lockedBooking->forceFill([
                'status' => BookingStatus::Rejected,
                'event_version' => $lockedBooking->event_version + 1,
            ])->save();

            $event = new BookingEvent;
            $event->forceFill([
                'organization_id' => $organization->getKey(),
                'booking_id' => $lockedBooking->getKey(),
                'event_type' => BookingEventType::StatusChanged,
                'actor_type' => 'user',
                'actor_user_id' => $actor->getKey(),
                'old_values' => $oldValues,
                'new_values' => $this->bookingSnapshot($lockedBooking),
                'reason' => $reason,
                'occurred_at' => now(),
            ]);
            $event->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'booking.home_visit.rejected',
                targetType: Booking::class,
                targetId: (string) $lockedBooking->getKey(),
                metadata: [
                    'source' => 'crm',
                    'status' => BookingStatus::Rejected->value,
                    'visit_format' => VisitFormat::HomeVisit->value,
                ],
            );

            return $lockedBooking->refresh();
        });
    }

    /** @return array<string, int|string> */
    private function bookingSnapshot(Booking $booking): array
    {
        return [
            'status' => $booking->status->value,
            'payment_status' => $booking->payment_status->value,
            'visit_format' => $booking->visit_format->value,
            'starts_at' => $booking->startsAtUtc()->toIso8601String(),
            'ends_at' => $booking->endsAtUtc()->toIso8601String(),
            'blocking_ends_at' => $booking->blockingEndsAtUtc()->toIso8601String(),
            'schedule_timezone' => $booking->schedule_timezone,
            'event_version' => $booking->event_version,
        ];
    }
}
