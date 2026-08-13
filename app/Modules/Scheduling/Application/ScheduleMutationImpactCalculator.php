<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\ValueObjects\ScheduleExceptionDefinition;
use App\Modules\Scheduling\Domain\ValueObjects\SpecialistScheduleDefinition;
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
        )));
    }

    public function forException(
        Specialist $specialist,
        ScheduleExceptionDefinition $definition,
    ): ScheduleMutationImpact {
        $bookings = $this->futureBookingsForSpecialist($specialist->getKey());

        return $this->fromBookings(array_values(array_filter(
            $bookings,
            fn (Booking $booking): bool => $this->isAffectedByException($booking, $specialist, $definition),
        )));
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
        )));
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
            return $this->fromBookings($this->futureBookingsForSpecialist($specialist->getKey()));
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

        return $this->fromBookings($this->futureBookingsForService($service->getKey()));
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
            ->all());
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

    /** @param array<int, Booking> $bookings */
    private function fromBookings(array $bookings): ScheduleMutationImpact
    {
        return new ScheduleMutationImpact(array_values(array_map(
            static fn (Booking $booking): int => $booking->getKey(),
            $bookings,
        )));
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
}
