<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scenarios\Application\AppointmentReminderScheduler;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scheduling\Domain\Enums\BookingEventType;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfirmBooking
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly BookingAuthorization $authorization,
        private readonly RecordBookingEvent $events,
        private readonly RecordScenarioEvent $scenarioEvents,
        private readonly AppointmentReminderScheduler $reminders,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Booking $booking, ?string $reason = null, ?int $expectedEventVersion = null): Booking
    {
        $this->authorization->authorize($actor, $booking);
        $reason = $this->reason($reason);
        $organization = $this->context->organization();

        return DB::transaction(function () use ($actor, $booking, $reason, $expectedEventVersion, $organization): Booking {
            $lockedBooking = Booking::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedBooking->status !== BookingStatus::Requested
                || ! in_array($lockedBooking->visit_format, [VisitFormat::Office, VisitFormat::Online], true)) {
                throw ValidationException::withMessages(['booking' => 'Only requested office or online bookings can be confirmed.']);
            }

            if ($expectedEventVersion !== null && (int) $lockedBooking->event_version !== $expectedEventVersion) {
                throw ValidationException::withMessages([
                    'expected_event_version' => 'This booking changed before confirmation was applied. Refresh and try again.',
                ]);
            }

            $oldValues = $this->events->snapshot($lockedBooking);
            $lockedBooking->forceFill([
                'status' => BookingStatus::Confirmed,
                'event_version' => $lockedBooking->event_version + 1,
            ])->save();
            $bookingEvent = $this->events->handle(
                booking: $lockedBooking,
                actor: $actor,
                type: BookingEventType::StatusChanged,
                oldValues: $oldValues,
                newValues: $this->events->snapshot($lockedBooking),
                reason: $reason,
            );
            $scenarioEvent = $this->scenarioEvents->bookingConfirmed(
                booking: $lockedBooking,
                causationId: (string) $bookingEvent->getKey(),
                occurredAt: CarbonImmutable::instance($bookingEvent->occurred_at),
            );
            $this->reminders->schedule($lockedBooking, $scenarioEvent);
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'booking.confirmed',
                targetType: Booking::class,
                targetId: (string) $lockedBooking->getKey(),
                metadata: [
                    'source' => 'crm',
                    'status' => BookingStatus::Confirmed->value,
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
            throw ValidationException::withMessages(['reason' => 'The confirmation reason is invalid.']);
        }

        return $reason;
    }
}
