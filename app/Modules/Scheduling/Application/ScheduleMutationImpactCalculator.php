<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use App\Modules\Scheduling\Domain\Models\SpecialistWorkingHour;
use App\Modules\Scheduling\Domain\ValueObjects\ScheduleExceptionDefinition;
use App\Modules\Scheduling\Domain\ValueObjects\SpecialistScheduleDefinition;
use App\Modules\Scheduling\Domain\ValueObjects\WallClockInterval;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class ScheduleMutationImpactCalculator
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function forWorkingHours(Specialist $specialist, SpecialistScheduleDefinition $definition): ScheduleMutationImpact
    {
        $bookings = $this->futureBookingsForSpecialist($specialist->getKey());

        return $this->fromBookings(array_values(array_filter(
            $bookings,
            fn (Booking $booking): bool => ! $this->fitsWorkingHours($booking, $specialist, $definition),
        )), [
            'type' => 'working_hours',
            'specialist_id' => $specialist->getKey(),
            'definition' => $definition->attributes(),
        ]);
    }

    public function forException(
        Specialist $specialist,
        ScheduleExceptionDefinition $definition,
    ): ScheduleMutationImpact {
        $bookings = $this->futureBookingsForSpecialist($specialist->getKey());

        return $this->fromBookings(array_values(array_filter(
            $bookings,
            fn (Booking $booking): bool => $this->isAffectedByException($booking, $specialist, $definition),
        )), [
            'type' => 'schedule_exception',
            'specialist_id' => $specialist->getKey(),
            'date' => $definition->date,
            'exception_type' => $definition->type->value,
            'start_time' => $definition->interval?->start,
            'end_time' => $definition->interval?->end,
        ]);
    }

    public function forUnavailablePeriod(
        Specialist $specialist,
        DateTimeInterface $startsAt,
        DateTimeInterface $endsAt,
    ): ScheduleMutationImpact {
        $start = CarbonImmutable::instance($startsAt)->utc();
        $end = CarbonImmutable::instance($endsAt)->utc();
        $bookings = $this->futureBookingsForSpecialist($specialist->getKey());

        return $this->fromBookings(array_values(array_filter(
            $bookings,
            fn (Booking $booking): bool => $booking->startsAtUtc()->lessThan($end)
                && $booking->blockingEndsAtUtc()->greaterThan($start),
        )), [
            'type' => 'unavailable_period',
            'specialist_id' => $specialist->getKey(),
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $end->toIso8601String(),
        ]);
    }

    public function forSpecialistChange(
        Specialist $specialist,
        bool $newIsActive,
        ?string $newTimezone,
    ): ScheduleMutationImpact {
        $organizationTimezone = $this->context->organization()->defaultTimezone();
        $currentTimezone = $specialist->timezone ?? $organizationTimezone;
        $effectiveNewTimezone = $newTimezone ?? $organizationTimezone;

        if (($specialist->is_active && ! $newIsActive) || $currentTimezone !== $effectiveNewTimezone) {
            return $this->fromBookings($this->futureBookingsForSpecialist($specialist->getKey()), [
                'type' => 'specialist_change',
                'specialist_id' => $specialist->getKey(),
                'is_active' => $newIsActive,
                'timezone' => $effectiveNewTimezone,
            ]);
        }

        return new ScheduleMutationImpact([]);
    }

    /** @param array<string, mixed> $attributes */
    public function forServiceChange(Service $service, array $attributes): ScheduleMutationImpact
    {
        $timingChanged = (int) ($attributes['duration_minutes'] ?? $service->duration_minutes) !== (int) $service->duration_minutes
            || (int) ($attributes['buffer_minutes'] ?? $service->buffer_minutes) !== (int) $service->buffer_minutes
            || ($attributes['formats'] ?? $service->formats) !== $service->formats
            || (bool) ($attributes['is_active'] ?? $service->is_active) !== (bool) $service->is_active
            || ($attributes['catalog_type'] ?? $service->catalogItemType()->value) !== $service->catalogItemType()->value;

        if (! $timingChanged) {
            return new ScheduleMutationImpact([]);
        }

        return $this->fromBookings($this->futureBookingsForService($service->getKey()), [
            'type' => 'service_change',
            'service_id' => $service->getKey(),
            'scheduling_intent' => $this->serviceSchedulingIntent($service, $attributes),
        ]);
    }

    public function forAssignmentRemoval(int $specialistId, int $serviceId): ScheduleMutationImpact
    {
        return $this->fromBookings(Booking::query()
            ->where('organization_id', $this->context->id())
            ->where('specialist_id', $specialistId)
            ->where('service_id', $serviceId)
            ->whereNotIn('status', BookingStatus::terminalValues())
            ->where('starts_at', '>=', now())
            ->orderBy('id')
            ->get()
            ->all(), [
                'type' => 'assignment_removal',
                'specialist_id' => $specialistId,
                'service_id' => $serviceId,
            ]);
    }

    public function forExceptionDeletion(Specialist $specialist, ScheduleException $exception): ScheduleMutationImpact
    {
        $remainingExceptions = ScheduleException::query()
            ->where('organization_id', $this->context->id())
            ->where('specialist_id', $specialist->getKey())
            ->where('is_active', true)
            ->where('id', '<>', $exception->getKey())
            ->get()
            ->groupBy(fn (ScheduleException $item): string => $item->dateKey());
        $workingHours = SpecialistWorkingHour::query()
            ->where('organization_id', $this->context->id())
            ->where('specialist_id', $specialist->getKey())
            ->where('is_active', true)
            ->get()
            ->groupBy('weekday');
        $date = $exception->dateKey();

        $affected = array_values(array_filter(
            $this->futureBookingsForSpecialist($specialist->getKey()),
            function (Booking $booking) use ($date, $remainingExceptions, $workingHours, $specialist): bool {
                $timezone = $specialist->timezone ?? $this->context->organization()->defaultTimezone();
                $localStart = $booking->startsAtUtc()->setTimezone($timezone);
                $localEnd = $booking->blockingEndsAtUtc()->setTimezone($timezone);

                if ($localStart->toDateString() !== $date) {
                    return false;
                }

                $dateExceptions = $remainingExceptions->get($date, collect());

                if ($dateExceptions->contains(
                    static fn (ScheduleException $item): bool => $item->exception_type === ScheduleExceptionType::DayOff,
                )) {
                    return true;
                }

                $customIntervals = $dateExceptions
                    ->filter(static fn (ScheduleException $item): bool => $item->exception_type === ScheduleExceptionType::CustomWindow)
                    ->map(static fn (ScheduleException $item) => $item->wallClockInterval())
                    ->filter()
                    ->all();
                $intervals = $customIntervals !== []
                    ? array_values($customIntervals)
                    : array_values($workingHours->get($localStart->dayOfWeekIso, collect())
                        ->map(static fn (SpecialistWorkingHour $item) => $item->wallClockInterval())
                        ->all());

                return ! $this->fitsWallClockIntervals($localStart, $localEnd, $intervals);
            },
        ));

        return $this->fromBookings($affected, [
            'type' => 'schedule_exception_deletion',
            'specialist_id' => $specialist->getKey(),
            'exception_id' => $exception->getKey(),
            'date' => $date,
        ]);
    }

    /** @return array<int, Booking> */
    private function futureBookingsForSpecialist(int $specialistId): array
    {
        return Booking::query()
            ->where('organization_id', $this->context->id())
            ->where('specialist_id', $specialistId)
            ->whereNotIn('status', BookingStatus::terminalValues())
            ->where('starts_at', '>=', now())
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** @return array<int, Booking> */
    private function futureBookingsForService(int $serviceId): array
    {
        return Booking::query()
            ->where('organization_id', $this->context->id())
            ->where('service_id', $serviceId)
            ->whereNotIn('status', BookingStatus::terminalValues())
            ->where('starts_at', '>=', now())
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** @param array<string, mixed> $attributes
     * @return array{duration_minutes: int|null, buffer_minutes: int, formats: list<mixed>, is_active: bool, catalog_type: string}
     */
    private function serviceSchedulingIntent(Service $service, array $attributes): array
    {
        $durationMinutes = $attributes['duration_minutes'] ?? $service->duration_minutes;
        $formats = $attributes['formats'] ?? $service->formats;
        $catalogType = $attributes['catalog_type'] ?? $service->catalogItemType()->value;

        return [
            'duration_minutes' => $durationMinutes === null ? null : (int) $durationMinutes,
            'buffer_minutes' => (int) ($attributes['buffer_minutes'] ?? $service->buffer_minutes),
            'formats' => is_array($formats) ? array_values($formats) : [],
            'is_active' => (bool) ($attributes['is_active'] ?? $service->is_active),
            'catalog_type' => $catalogType instanceof CatalogItemType
                ? $catalogType->value
                : (string) $catalogType,
        ];
    }

    /**
     * @param  array<int, Booking>  $bookings
     * @param  array<string, mixed>  $mutation
     */
    private function fromBookings(array $bookings, array $mutation = []): ScheduleMutationImpact
    {
        $bookingIds = array_values(array_map(
            static fn (Booking $booking): int => $booking->getKey(),
            $bookings,
        ));

        if ($bookingIds === []) {
            return new ScheduleMutationImpact([]);
        }

        $records = Booking::query()
            ->where('organization_id', $this->context->id())
            ->whereIn('id', $bookingIds)
            ->with(['client', 'service', 'specialist'])
            ->orderBy('id')
            ->get();
        $projections = $records->map(function (Booking $booking): array {
            $timezone = $this->context->defaultTimezone();

            return [
                'id' => $booking->getKey(),
                'client' => $booking->client->full_name,
                'service' => $booking->service->name,
                'specialist' => $booking->specialist->display_name,
                'local_start' => $booking->startsAtUtc()->setTimezone($timezone)->format('Y-m-d H:i'),
                'timezone' => $timezone,
                'format' => $booking->visit_format->value,
                'status' => $booking->status->value,
            ];
        })->values()->all();
        $state = $records->map(static fn (Booking $booking): array => [
            'id' => $booking->getKey(),
            'starts_at' => $booking->startsAtUtc()->toIso8601String(),
            'ends_at' => $booking->endsAtUtc()->toIso8601String(),
            'blocking_ends_at' => $booking->blockingEndsAtUtc()->toIso8601String(),
            'status' => $booking->status->value,
            'event_version' => $booking->event_version,
        ])->values()->all();
        $digest = hash('sha256', json_encode([
            'mutation' => $mutation,
            'bookings' => $state,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return new ScheduleMutationImpact($bookingIds, array_values($projections), $digest);
    }

    private function fitsWorkingHours(
        Booking $booking,
        Specialist $specialist,
        SpecialistScheduleDefinition $definition,
    ): bool {
        $localStart = $booking->startsAtUtc()->setTimezone($specialist->timezone ?? $this->context->organization()->defaultTimezone());
        $localEnd = $booking->blockingEndsAtUtc()->setTimezone($specialist->timezone ?? $this->context->organization()->defaultTimezone());
        $intervals = $definition->forWeekday($localStart->dayOfWeekIso);

        foreach ($intervals as $interval) {
            if ($localStart->format('Y-m-d') === $localEnd->format('Y-m-d')
                && $localStart->format('H:i') >= $interval->interval->start
                && $localEnd->format('H:i') <= $interval->interval->end) {
                return true;
            }
        }

        return false;
    }

    private function isAffectedByException(
        Booking $booking,
        Specialist $specialist,
        ScheduleExceptionDefinition $definition,
    ): bool {
        $localStart = $booking->startsAtUtc()->setTimezone($specialist->timezone ?? $this->context->organization()->defaultTimezone());
        $localEnd = $booking->blockingEndsAtUtc()->setTimezone($specialist->timezone ?? $this->context->organization()->defaultTimezone());

        if ($localStart->toDateString() !== $definition->date) {
            return false;
        }

        if ($definition->type === ScheduleExceptionType::DayOff) {
            return true;
        }

        return $definition->interval === null
            || $localStart->format('H:i') < $definition->interval->start
            || $localEnd->format('H:i') > $definition->interval->end;
    }

    /** @param list<WallClockInterval> $intervals */
    private function fitsWallClockIntervals(CarbonImmutable $localStart, CarbonImmutable $localEnd, array $intervals): bool
    {
        foreach ($intervals as $interval) {
            if ($localStart->format('Y-m-d') === $localEnd->format('Y-m-d')
                && $localStart->format('H:i') >= $interval->start
                && $localEnd->format('H:i') <= $interval->end) {
                return true;
            }
        }

        return false;
    }
}
