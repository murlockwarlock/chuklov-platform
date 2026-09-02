<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scheduling\Domain\Enums\BookingEventType;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancelBooking
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly BookingAuthorization $authorization,
        private readonly GetBookingCancellationCutoff $cutoff,
        private readonly RecordBookingEvent $events,
        private readonly RecordScenarioEvent $scenarioEvents,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User|Client $actor, Booking $booking, ?string $reason = null): Booking
    {
        $this->authorization->authorize($actor, $booking);
        $reason = $this->normalizeReason($reason);
        $organization = $this->context->organization();

        return DB::transaction(function () use ($actor, $booking, $reason, $organization): Booking {
            $lockedBooking = Booking::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($lockedBooking->status->value, BookingStatus::terminalValues(), true)) {
                throw ValidationException::withMessages(['booking' => 'This booking is already terminal.']);
            }

            $pendingHomeVisit = $lockedBooking->status === BookingStatus::PendingReview
                && $lockedBooking->visit_format === VisitFormat::HomeVisit;

            if (! $pendingHomeVisit && $lockedBooking->status !== BookingStatus::Requested
                && $lockedBooking->status !== BookingStatus::Confirmed) {
                throw ValidationException::withMessages(['booking' => 'This booking cannot be cancelled.']);
            }

            if ($actor instanceof Client && ! $pendingHomeVisit && ! $this->isOutsideCutoff($lockedBooking)) {
                throw ValidationException::withMessages([
                    'booking' => 'Self-service cancellation is closed. Please contact staff.',
                ]);
            }

            if ($actor instanceof User && ! $pendingHomeVisit && ! $this->isOutsideCutoff($lockedBooking)
                && $reason === null) {
                throw ValidationException::withMessages([
                    'reason' => 'A reason is required when cancelling inside the configured cutoff.',
                ]);
            }

            $oldValues = $this->events->snapshot($lockedBooking);
            $lockedBooking->forceFill([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => now(),
                'event_version' => $lockedBooking->event_version + 1,
            ])->save();
            $bookingEvent = $this->events->handle(
                booking: $lockedBooking,
                actor: $actor,
                type: BookingEventType::Cancelled,
                oldValues: $oldValues,
                newValues: $this->events->snapshot($lockedBooking),
                reason: $reason,
            );
            $this->scenarioEvents->bookingCancelled(
                booking: $lockedBooking,
                causationId: (string) $bookingEvent->getKey(),
                occurredAt: CarbonImmutable::instance($bookingEvent->occurred_at),
            );
            $this->audit->handle(
                organization: $organization,
                actor: $actor instanceof User ? $actor : null,
                action: $pendingHomeVisit ? 'booking.home_visit.withdrawn' : 'booking.cancelled',
                targetType: Booking::class,
                targetId: (string) $lockedBooking->getKey(),
                metadata: [
                    'source' => $actor instanceof User ? 'crm' : 'portal',
                    'status' => BookingStatus::Cancelled->value,
                    'inside_cutoff' => ! $pendingHomeVisit && ! $this->isOutsideCutoff($lockedBooking),
                ],
            );

            return $lockedBooking->refresh();
        });
    }

    private function isOutsideCutoff(Booking $booking): bool
    {
        return $booking->startsAtUtc()->greaterThanOrEqualTo(
            CarbonImmutable::instance(now())->utc()->addMinutes($this->cutoff->handle()),
        );
    }

    private function normalizeReason(?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);

        if ($reason !== null && ($reason === '' || mb_strlen($reason) > 500)) {
            throw ValidationException::withMessages(['reason' => 'The cancellation reason is invalid.']);
        }

        return $reason;
    }
}
