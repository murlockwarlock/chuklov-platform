<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use App\Modules\Scheduling\Domain\Models\SpecialistWorkingHour;
use App\Modules\Scheduling\Domain\Models\UnavailablePeriod;
use App\Modules\Scheduling\Domain\Services\SlotCalculator;
use App\Modules\Scheduling\Domain\ValueObjects\InstantInterval;
use App\Modules\Scheduling\Domain\ValueObjects\LocalDate;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class EnsureSpecialistIntervalAvailable
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly GetBookingLeadTime $leadTime,
        private readonly SlotCalculator $calculator,
    ) {}

    public function handle(
        Specialist $specialist,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?int $ignoreUnavailablePeriodId = null,
        ?CarbonImmutable $now = null,
    ): string {
        $organization = $this->context->organization();

        if ((int) $specialist->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The specialist is outside the current organization.');
        }

        if (! $specialist->is_active) {
            throw ValidationException::withMessages(['specialist_id' => 'The selected specialist is not active.']);
        }

        $startsAt = $startsAt->utc();
        $endsAt = $endsAt->utc();

        if ($startsAt->greaterThanOrEqualTo($endsAt)) {
            throw ValidationException::withMessages(['starts_at' => 'The selected sales-call interval is invalid.']);
        }

        $scheduleTimezone = IanaTimezone::from(
            $specialist->timezone ?? $organization->defaultTimezone(),
        )->value;
        $localStart = $startsAt->setTimezone($scheduleTimezone);
        $localEnd = $endsAt->subSecond()->setTimezone($scheduleTimezone);

        if ($localStart->toDateString() !== $localEnd->toDateString()) {
            throw ValidationException::withMessages([
                'starts_at' => 'The sales call must fit within one specialist working day.',
            ]);
        }

        $date = LocalDate::from($localStart->toDateString());
        $rangeStart = $this->localBoundary($date, $scheduleTimezone);
        $rangeEnd = $this->localBoundary($date->nextDay(), $scheduleTimezone);
        $workingHours = SpecialistWorkingHour::query()
            ->where('organization_id', $organization->getKey())
            ->where('specialist_id', $specialist->getKey())
            ->where('is_active', true)
            ->get()
            ->groupBy('weekday');
        $exceptions = ScheduleException::query()
            ->where('organization_id', $organization->getKey())
            ->where('specialist_id', $specialist->getKey())
            ->where('exception_date', $date->value)
            ->where('is_active', true)
            ->get();
        $unavailableIntervals = array_values(UnavailablePeriod::query()
            ->where('organization_id', $organization->getKey())
            ->where('specialist_id', $specialist->getKey())
            ->when(
                $ignoreUnavailablePeriodId !== null,
                fn ($query) => $query->where('id', '<>', $ignoreUnavailablePeriodId),
            )
            ->where('starts_at', '<', $rangeEnd)
            ->where('ends_at', '>', $rangeStart)
            ->get()
            ->map(fn (UnavailablePeriod $period): InstantInterval => $period->instantInterval())
            ->all());
        $bookingIntervals = array_values(Booking::query()
            ->where('organization_id', $organization->getKey())
            ->where('specialist_id', $specialist->getKey())
            ->whereIn('status', BookingStatus::blockingValues())
            ->where('starts_at', '<', $rangeEnd)
            ->where('blocking_ends_at', '>', $rangeStart)
            ->get()
            ->map(fn (Booking $booking): InstantInterval => $booking->instantInterval())
            ->all());
        $dateExceptions = $exceptions->groupBy(
            fn (ScheduleException $exception): string => $exception->dateKey(),
        )->get($date->value, collect());
        $customIntervals = array_values($dateExceptions
            ->filter(fn (ScheduleException $exception): bool => $exception->exception_type === ScheduleExceptionType::CustomWindow)
            ->map(fn (ScheduleException $exception) => $exception->wallClockInterval())
            ->filter()
            ->values()
            ->all());
        $dayOff = $dateExceptions->contains(
            fn (ScheduleException $exception): bool => $exception->exception_type === ScheduleExceptionType::DayOff,
        );
        $slots = $this->calculator->calculate(
            date: $date,
            scheduleTimezone: $scheduleTimezone,
            workingIntervals: array_values($workingHours->get($date->weekday(), collect())
                ->map(fn (SpecialistWorkingHour $workingHour) => $workingHour->wallClockInterval())
                ->all()),
            customIntervals: $customIntervals,
            dayOff: $dayOff,
            unavailableIntervals: $unavailableIntervals,
            bookingIntervals: $bookingIntervals,
            durationMinutes: (int) round($startsAt->diffInMinutes($endsAt)),
            bufferMinutes: 0,
            leadTimeMinutes: $this->leadTime->handle(),
            now: $now?->utc() ?? CarbonImmutable::instance(now())->utc(),
            format: VisitFormat::Online,
            displayTimezone: $scheduleTimezone,
            stepMinutes: 1,
        );

        foreach ($slots as $slot) {
            if ($slot->startsAt->equalTo($startsAt) && $slot->endsAt->equalTo($endsAt)) {
                return $scheduleTimezone;
            }
        }

        throw ValidationException::withMessages([
            'starts_at' => 'The selected sales-call time is no longer available.',
        ]);
    }

    private function localBoundary(LocalDate $date, string $timezone): CarbonImmutable
    {
        [$year, $month, $day] = array_map('intval', explode('-', $date->value));
        $wallStart = CarbonImmutable::createSafe($year, $month, $day, 0, 0, 0, new DateTimeZone('UTC'));

        if (! $wallStart instanceof CarbonImmutable) {
            throw ValidationException::withMessages([
                'starts_at' => 'The specialist schedule date is invalid.',
            ]);
        }

        $wallEnd = $wallStart->addDay();
        $zone = new DateTimeZone($timezone);
        $windowStart = $wallStart->subDays(3)->getTimestamp();
        $windowEnd = $wallEnd->addDays(3)->getTimestamp();
        $transitions = $zone->getTransitions($windowStart, $windowEnd);
        $earliest = null;
        $latest = null;

        foreach ($transitions as $index => $transition) {
            $segmentStart = max($windowStart, (int) $transition['ts']);
            $segmentEnd = $index + 1 < count($transitions)
                ? min($windowEnd, (int) $transitions[$index + 1]['ts'])
                : $windowEnd;
            $offset = (int) $transition['offset'];
            $candidateStart = max($segmentStart, $wallStart->getTimestamp() - $offset);
            $candidateEnd = min($segmentEnd, $wallEnd->getTimestamp() - $offset);

            if ($candidateStart >= $candidateEnd) {
                continue;
            }

            $earliest = $earliest === null ? $candidateStart : min($earliest, $candidateStart);
            $latest = $latest === null ? $candidateEnd : max($latest, $candidateEnd);
        }

        if ($earliest === null || $latest === null) {
            throw ValidationException::withMessages([
                'starts_at' => 'The specialist schedule date has no valid instants.',
            ]);
        }

        return CarbonImmutable::createFromTimestampUTC($earliest);
    }
}
