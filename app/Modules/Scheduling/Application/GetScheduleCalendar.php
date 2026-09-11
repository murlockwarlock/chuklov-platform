<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use App\Modules\Scheduling\Domain\ValueObjects\LocalDate;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class GetScheduleCalendar
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly ResolveSpecialistWorkingHours $workingHoursResolver,
    ) {}

    /** @return array<string, array{date: string, weekday: int, is_working: bool, exception_type: string|null, intervals: list<array{start: string, end: string, start_minutes: int, end_minutes: int}>}> */
    public function forSpecialist(
        Specialist $specialist,
        string $dateFrom,
        string $dateTo,
        ?string $displayTimezone = null,
    ): array {
        if ((int) $specialist->organization_id !== $this->context->id()) {
            throw new InvalidArgumentException('The specialist is outside the current organization.');
        }

        $displayTimezone = IanaTimezone::from($displayTimezone ?? $this->context->defaultTimezone())->value;
        $scheduleTimezone = IanaTimezone::from($specialist->timezone ?? $this->context->defaultTimezone())->value;
        $displayStart = $this->parseDate($dateFrom, $displayTimezone);
        $displayEnd = $this->parseDate($dateTo, $displayTimezone);

        if ($displayStart->greaterThan($displayEnd)) {
            throw new InvalidArgumentException('The schedule date range is invalid.');
        }

        $cells = [];
        for ($date = $displayStart; $date->lessThanOrEqualTo($displayEnd); $date = $date->addDay()) {
            $key = $date->toDateString();
            $cells[$key] = [
                'date' => $key,
                'weekday' => $date->dayOfWeekIso,
                'is_working' => false,
                'exception_type' => null,
                'intervals' => [],
            ];
        }

        $scheduleStart = $displayStart->subDays(2)->setTimezone($scheduleTimezone)->toDateString();
        $scheduleEnd = $displayEnd->addDays(2)->setTimezone($scheduleTimezone)->toDateString();
        $workingHours = $this->workingHoursResolver->forRange(
            specialist: $specialist,
            dateFrom: LocalDate::from($scheduleStart),
            dateTo: LocalDate::from($scheduleEnd),
        );
        $exceptions = ScheduleException::query()
            ->where('organization_id', $this->context->id())
            ->where('specialist_id', $specialist->getKey())
            ->whereBetween('exception_date', [$scheduleStart, $scheduleEnd])
            ->where('is_active', true)
            ->get()
            ->groupBy(fn (ScheduleException $exception): string => $exception->dateKey());

        $scheduleDate = $this->parseDate($scheduleStart, $scheduleTimezone);
        $lastScheduleDate = $this->parseDate($scheduleEnd, $scheduleTimezone);

        for (; $scheduleDate->lessThanOrEqualTo($lastScheduleDate); $scheduleDate = $scheduleDate->addDay()) {
            $dateKey = $scheduleDate->toDateString();
            $localDate = LocalDate::from($dateKey);
            $dateExceptions = $exceptions->get($dateKey, collect());

            if ($dateExceptions->contains(
                static fn (ScheduleException $exception): bool => $exception->exception_type === ScheduleExceptionType::DayOff,
            )) {
                $dayOffIntervals = collect($this->workingHoursResolver->intervalsForDate($workingHours, $localDate));
                if ($dayOffIntervals->isEmpty()) {
                    $displayDateKey = $scheduleDate->setTimezone($displayTimezone)->toDateString();
                    if (isset($cells[$displayDateKey])) {
                        $cells[$displayDateKey]['exception_type'] = ScheduleExceptionType::DayOff->value;
                    }
                } else {
                    foreach ($dayOffIntervals as $interval) {
                        $this->markDisplayIntervalAsDayOff(
                            $cells,
                            $this->parseDateTime($dateKey, $interval->start, $scheduleTimezone),
                            $this->parseDateTime($dateKey, $interval->end, $scheduleTimezone),
                            $displayTimezone,
                        );
                    }
                }

                continue;
            }

            $customIntervals = $dateExceptions
                ->filter(static fn (ScheduleException $exception): bool => $exception->exception_type === ScheduleExceptionType::CustomWindow)
                ->map(static fn (ScheduleException $exception) => $exception->wallClockInterval())
                ->filter()
                ->values();
            $intervals = $customIntervals->isNotEmpty()
                ? $customIntervals->all()
                : $this->workingHoursResolver->intervalsForDate($workingHours, $localDate);

            foreach ($intervals as $interval) {
                $start = $this->parseDateTime($dateKey, $interval->start, $scheduleTimezone);
                $end = $this->parseDateTime($dateKey, $interval->end, $scheduleTimezone);
                $this->addDisplayInterval($cells, $start, $end, $displayTimezone, $dateExceptions);
            }
        }

        foreach ($cells as &$cell) {
            usort(
                $cell['intervals'],
                static fn (array $left, array $right): int => $left['start_minutes'] <=> $right['start_minutes'],
            );
            $cell['is_working'] = $cell['intervals'] !== [];
        }
        unset($cell);

        return $cells;
    }

    /**
     * @param  array<string, array{date: string, weekday: int, is_working: bool, exception_type: string|null, intervals: list<array{start: string, end: string, start_minutes: int, end_minutes: int}>}>  $cells
     * @param  Collection<int, ScheduleException>  $exceptions
     */
    private function addDisplayInterval(
        array &$cells,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $displayTimezone,
        Collection $exceptions,
    ): void {
        $displayStart = $start->setTimezone($displayTimezone);
        $displayEnd = $end->setTimezone($displayTimezone);
        $cursor = $displayStart;

        while ($cursor->lessThan($displayEnd)) {
            $dayEnd = $cursor->startOfDay()->addDay();
            $segmentEnd = $displayEnd->lessThan($dayEnd) ? $displayEnd : $dayEnd;
            $dateKey = $cursor->toDateString();

            if (isset($cells[$dateKey]) && $cursor->lessThan($segmentEnd)) {
                $cells[$dateKey]['intervals'][] = [
                    'start' => $cursor->format('H:i'),
                    'end' => $segmentEnd->format('H:i'),
                    'start_minutes' => ((int) $cursor->format('H')) * 60 + (int) $cursor->format('i'),
                    'end_minutes' => ((int) $segmentEnd->format('H')) * 60 + (int) $segmentEnd->format('i'),
                ];
                $cells[$dateKey]['exception_type'] = $exceptions->contains(
                    static fn (ScheduleException $exception): bool => $exception->exception_type === ScheduleExceptionType::CustomWindow,
                ) ? ScheduleExceptionType::CustomWindow->value : null;
            }

            $cursor = $segmentEnd;
        }
    }

    /**
     * @param  array<string, array{date: string, weekday: int, is_working: bool, exception_type: string|null, intervals: list<array{start: string, end: string, start_minutes: int, end_minutes: int}>}>  $cells
     */
    private function markDisplayIntervalAsDayOff(
        array &$cells,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $displayTimezone,
    ): void {
        $displayStart = $start->setTimezone($displayTimezone);
        $displayEnd = $end->setTimezone($displayTimezone);
        $cursor = $displayStart;

        while ($cursor->lessThan($displayEnd)) {
            $dayEnd = $cursor->startOfDay()->addDay();
            $segmentEnd = $displayEnd->lessThan($dayEnd) ? $displayEnd : $dayEnd;
            $dateKey = $cursor->toDateString();

            if (isset($cells[$dateKey]) && $cursor->lessThan($segmentEnd)) {
                $cells[$dateKey]['exception_type'] = ScheduleExceptionType::DayOff->value;
            }

            $cursor = $segmentEnd;
        }
    }

    private function parseDate(string $value, string $timezone): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone($timezone));

        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('The schedule date is invalid.');
        }

        return $date;
    }

    private function parseDateTime(string $date, string $time, string $timezone): CarbonImmutable
    {
        $value = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.substr($time, 0, 5), new DateTimeZone($timezone));

        if (! $value instanceof CarbonImmutable) {
            throw new InvalidArgumentException('The schedule time is invalid.');
        }

        return $value;
    }
}
