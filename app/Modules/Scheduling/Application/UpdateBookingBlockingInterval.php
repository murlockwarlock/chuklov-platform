<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Enums\BookingEventType;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\ValueObjects\AvailabilitySlot;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateBookingBlockingInterval
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly BookingAuthorization $authorization,
        private readonly CalculateAvailability $availability,
        private readonly RecordBookingEvent $events,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        Booking $booking,
        DateTimeInterface $blockingEndsAt,
        ?int $expectedEventVersion = null,
    ): Booking {
        $this->authorization->authorize($actor, $booking);
        $organization = $this->context->organization();
        $requestedEnd = CarbonImmutable::instance($blockingEndsAt)->utc();

        return DB::transaction(function () use (
            $actor,
            $booking,
            $requestedEnd,
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
                    'expected_event_version' => 'Эта запись уже изменилась. Обновите страницу и попробуйте ещё раз.',
                ]);
            }

            if (! in_array($lockedBooking->status->value, BookingStatus::blockingValues(), true)) {
                throw ValidationException::withMessages([
                    'booking' => 'Для этой записи нельзя изменить занятое время в календаре.',
                ]);
            }

            if (! $requestedEnd->greaterThan($lockedBooking->startsAtUtc())) {
                throw ValidationException::withMessages([
                    'blockingEndsAt' => 'Время занятости в календаре должно быть позже начала записи.',
                ]);
            }

            $specialist = $lockedBooking->specialist()->where('organization_id', $organization->getKey())->lockForUpdate()->firstOrFail();
            $service = $lockedBooking->service()->where('organization_id', $organization->getKey())->lockForUpdate()->firstOrFail();
            $availability = $this->availability->forBooking(
                specialist: $specialist,
                service: $service,
                format: $lockedBooking->visit_format,
                startsAt: $lockedBooking->startsAtUtc(),
                displayTimezone: $lockedBooking->schedule_timezone,
                ignoreBookingId: $lockedBooking->getKey(),
                leadTimeMinutes: 0,
                now: $lockedBooking->startsAtUtc()->subSecond(),
                workingLocationId: $lockedBooking->working_location_id,
                locationArea: $lockedBooking->location_area,
                allowInactiveLocation: true,
                blockingDurationMinutes: (int) $lockedBooking->startsAtUtc()->diffInMinutes($requestedEnd),
            );
            $slot = null;
            foreach ($availability->slots as $candidate) {
                if ($candidate->startsAt->equalTo($lockedBooking->startsAtUtc())) {
                    $slot = $candidate;

                    break;
                }
            }

            if (! $slot instanceof AvailabilitySlot || ! $slot->blockingEndsAt->equalTo($requestedEnd)) {
                throw ValidationException::withMessages([
                    'blockingEndsAt' => 'Указанное время выходит за расписание или пересекается с другой записью.',
                ]);
            }

            $oldValues = $this->events->snapshot($lockedBooking);
            $lockedBooking->forceFill([
                'blocking_ends_at' => $slot->blockingEndsAt,
                'event_version' => $lockedBooking->event_version + 1,
            ]);

            try {
                $lockedBooking->save();
            } catch (QueryException $exception) {
                if ($this->isBookingConflict($exception)) {
                    throw ValidationException::withMessages([
                        'blockingEndsAt' => 'Указанное время пересекается с другой записью.',
                    ]);
                }

                throw $exception;
            }

            $this->events->handle(
                booking: $lockedBooking,
                actor: $actor,
                type: BookingEventType::BlockingIntervalUpdated,
                oldValues: $oldValues,
                newValues: $this->events->snapshot($lockedBooking),
            );
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'booking.blocking_interval.updated',
                targetType: Booking::class,
                targetId: (string) $lockedBooking->getKey(),
                metadata: [
                    'source' => 'crm',
                    'status' => $lockedBooking->status->value,
                    'visit_format' => $lockedBooking->visit_format->value,
                ],
            );

            return $lockedBooking->refresh();
        });
    }

    private function isBookingConflict(QueryException $exception): bool
    {
        $sqlState = $exception->getCode() ?: ($exception->errorInfo[0] ?? null);

        return in_array($sqlState, ['23P01', '40P01'], true);
    }
}
