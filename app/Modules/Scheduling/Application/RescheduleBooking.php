<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scheduling\Domain\Enums\BookingEventType;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\ValueObjects\AvailabilitySlot;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RescheduleBooking
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly BookingAuthorization $authorization,
        private readonly GetBookingCancellationCutoff $cutoff,
        private readonly CalculateAvailability $availability,
        private readonly RecordBookingEvent $events,
        private readonly RecordScenarioEvent $scenarioEvents,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User|Client $actor,
        Booking $booking,
        DateTimeInterface $newStartsAt,
        ?string $clientTimezone = null,
        ?string $reason = null,
        ?int $expectedEventVersion = null,
    ): Booking {
        $this->authorization->authorize($actor, $booking);
        $reason = $this->normalizeReason($reason);
        $newStartsAt = CarbonImmutable::instance($newStartsAt)->utc();
        $organization = $this->context->organization();

        return DB::transaction(function () use (
            $actor,
            $booking,
            $newStartsAt,
            $clientTimezone,
            $reason,
            $expectedEventVersion,
            $organization,
        ): Booking {
            $lockedBooking = Booking::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($expectedEventVersion === null || $lockedBooking->event_version !== $expectedEventVersion) {
                throw ValidationException::withMessages([
                    'expected_event_version' => 'This booking changed before the reschedule was applied. Refresh and try again.',
                ]);
            }

            if (in_array($lockedBooking->status->value, BookingStatus::terminalValues(), true)) {
                throw ValidationException::withMessages(['booking' => 'This booking cannot be rescheduled.']);
            }

            $pendingHomeVisit = $lockedBooking->status === BookingStatus::PendingReview
                && $lockedBooking->visit_format === VisitFormat::HomeVisit;

            if (! $pendingHomeVisit && ! in_array($lockedBooking->status, [BookingStatus::Requested, BookingStatus::Confirmed], true)) {
                throw ValidationException::withMessages(['booking' => 'This booking cannot be rescheduled.']);
            }

            $outsideCutoff = $pendingHomeVisit || $this->isOutsideCutoff($lockedBooking);

            if ($actor instanceof Client && ! $outsideCutoff) {
                throw ValidationException::withMessages([
                    'booking' => 'Self-service rescheduling is closed. Please contact staff.',
                ]);
            }

            if ($actor instanceof User && ! $outsideCutoff && $reason === null) {
                throw ValidationException::withMessages([
                    'reason' => 'A reason is required when rescheduling inside the configured cutoff.',
                ]);
            }

            $client = Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($lockedBooking->client_id)
                ->lockForUpdate()
                ->firstOrFail();
            $specialist = Specialist::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($lockedBooking->specialist_id)
                ->lockForUpdate()
                ->firstOrFail();
            $service = Service::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($lockedBooking->service_id)
                ->lockForUpdate()
                ->firstOrFail();
            $resolvedTimezone = $this->resolveTimezone($clientTimezone ?? $client->timezone);
            $availability = $this->availability->forBooking(
                specialist: $specialist,
                service: $service,
                format: $lockedBooking->visit_format,
                startsAt: $newStartsAt,
                displayTimezone: $resolvedTimezone,
                ignoreBookingId: $lockedBooking->getKey(),
            );
            $slot = $this->matchingSlot($availability->slots, $newStartsAt);

            if (! $slot instanceof AvailabilitySlot) {
                throw ValidationException::withMessages([
                    'startsAt' => 'The selected time is no longer available.',
                ]);
            }

            $oldValues = $this->events->snapshot($lockedBooking);
            $lockedBooking->forceFill([
                'starts_at' => $slot->startsAt,
                'ends_at' => $slot->endsAt,
                'blocking_ends_at' => $slot->blockingEndsAt,
                'schedule_timezone' => $slot->scheduleTimezone,
                'client_timezone' => $resolvedTimezone,
                'event_version' => $lockedBooking->event_version + 1,
            ]);

            try {
                $lockedBooking->save();
            } catch (QueryException $exception) {
                if ($this->isBookingConflict($exception)) {
                    throw ValidationException::withMessages([
                        'startsAt' => 'The selected time was taken concurrently. The existing booking time was preserved.',
                    ]);
                }

                throw $exception;
            }

            $bookingEvent = $this->events->handle(
                booking: $lockedBooking,
                actor: $actor,
                type: BookingEventType::Rescheduled,
                oldValues: $oldValues,
                newValues: $this->events->snapshot($lockedBooking),
                reason: $reason,
            );
            if ($lockedBooking->status === BookingStatus::Confirmed) {
                $this->scenarioEvents->bookingConfirmed(
                    booking: $lockedBooking,
                    causationId: (string) $bookingEvent->getKey(),
                    occurredAt: CarbonImmutable::instance($bookingEvent->occurred_at),
                );
            }
            $this->audit->handle(
                organization: $organization,
                actor: $actor instanceof User ? $actor : null,
                action: 'booking.rescheduled',
                targetType: Booking::class,
                targetId: (string) $lockedBooking->getKey(),
                metadata: [
                    'source' => $actor instanceof User ? 'crm' : 'portal',
                    'status' => $lockedBooking->status->value,
                    'visit_format' => $lockedBooking->visit_format->value,
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

    private function resolveTimezone(?string $timezone): string
    {
        try {
            return IanaTimezone::from($timezone ?? 'UTC')->value;
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['clientTimezone' => 'The client timezone must be an IANA timezone.']);
        }
    }

    /** @param list<AvailabilitySlot> $slots */
    private function matchingSlot(array $slots, CarbonImmutable $startsAt): ?AvailabilitySlot
    {
        foreach ($slots as $slot) {
            if ($slot->startsAt->equalTo($startsAt)) {
                return $slot;
            }
        }

        return null;
    }

    private function normalizeReason(?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);

        if ($reason !== null && ($reason === '' || mb_strlen($reason) > 500)) {
            throw ValidationException::withMessages(['reason' => 'The reschedule reason is invalid.']);
        }

        return $reason;
    }

    private function isBookingConflict(QueryException $exception): bool
    {
        $sqlState = $exception->getCode() ?: ($exception->errorInfo[0] ?? null);

        return in_array($sqlState, ['23P01', '40P01'], true);
    }
}
