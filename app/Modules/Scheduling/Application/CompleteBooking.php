<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Enums\BookingEventType;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CompleteBooking
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly BookingAuthorization $authorization,
        private readonly RecordBookingEvent $events,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Booking $booking, ?string $reason = null): Booking
    {
        $this->authorization->authorize($actor, $booking);
        $reason = $this->reason($reason);
        $organization = $this->context->organization();

        return DB::transaction(function () use ($actor, $booking, $reason, $organization): Booking {
            $lockedBooking = Booking::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBooking->status !== BookingStatus::Confirmed) {
                throw ValidationException::withMessages(['booking' => 'Only confirmed bookings can be completed.']);
            }

            if (CarbonImmutable::instance(now())->utc()->lessThan($lockedBooking->endsAtUtc())) {
                throw ValidationException::withMessages(['booking' => 'A booking can only be completed after its scheduled end.']);
            }

            $oldValues = $this->events->snapshot($lockedBooking);
            $lockedBooking->forceFill([
                'status' => BookingStatus::Completed,
                'event_version' => $lockedBooking->event_version + 1,
            ])->save();
            $this->events->handle(
                booking: $lockedBooking,
                actor: $actor,
                type: BookingEventType::Completed,
                oldValues: $oldValues,
                newValues: $this->events->snapshot($lockedBooking),
                reason: $reason,
            );
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'booking.completed',
                targetType: Booking::class,
                targetId: (string) $lockedBooking->getKey(),
                metadata: [
                    'source' => 'crm',
                    'status' => BookingStatus::Completed->value,
                    'visit_format' => $lockedBooking->visit_format->value,
                ],
            );

            return $lockedBooking->refresh();
        });
    }

    private function reason(?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);

        if ($reason !== null && ($reason === '' || mb_strlen($reason) > 500)) {
            throw ValidationException::withMessages(['reason' => 'The completion reason is invalid.']);
        }

        return $reason;
    }
}
