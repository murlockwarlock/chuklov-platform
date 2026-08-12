<?php

namespace App\Modules\Scheduling\Domain\Services;

use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\ValueObjects\AvailabilitySlot;
use App\Modules\Scheduling\Domain\ValueObjects\InstantInterval;
use App\Modules\Scheduling\Domain\ValueObjects\LocalDate;
use App\Modules\Scheduling\Domain\ValueObjects\WallClockInterval;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Throwable;

class SlotCalculator
{
    /**
     * @param  list<WallClockInterval>  $workingIntervals
     * @param  list<WallClockInterval>  $customIntervals
     * @param  list<InstantInterval>  $unavailableIntervals
     * @param  list<InstantInterval>  $bookingIntervals
     * @return list<AvailabilitySlot>
     */
    public function calculate(
        LocalDate $date,
        string $scheduleTimezone,
        array $workingIntervals,
        array $customIntervals,
        bool $dayOff,
        array $unavailableIntervals,
        array $bookingIntervals,
        int $durationMinutes,
        int $bufferMinutes,
        int $leadTimeMinutes,
        CarbonImmutable $now,
        VisitFormat $format,
        string $displayTimezone,
        ?int $stepMinutes = null,
    ): array {
        if ($dayOff || $durationMinutes <= 0 || $bufferMinutes < 0 || $leadTimeMinutes < 0) {
            return [];
        }

        $stepMinutes ??= $durationMinutes + $bufferMinutes;

        if ($stepMinutes <= 0) {
            return [];
        }

        $wallIntervals = $customIntervals !== [] ? $customIntervals : $workingIntervals;
        $blockedIntervals = $this->mergeIntervals([...$unavailableIntervals, ...$bookingIntervals]);
        $slots = [];
        $minimumStart = $now->utc()->addMinutes($leadTimeMinutes);

        foreach ($wallIntervals as $wallInterval) {
            $base = $this->toInstantInterval($date, $scheduleTimezone, $wallInterval);

            if ($base === null) {
                continue;
            }

            foreach ($this->subtract($base, $blockedIntervals) as $segment) {
                for ($minute = $wallInterval->startMinutes(); $minute <= $wallInterval->endMinutes(); $minute += $stepMinutes) {
                    if ($minute > 1439) {
                        break;
                    }

                    $candidate = $this->localInstant($date, $scheduleTimezone, $minute);

                    if (! $candidate instanceof CarbonImmutable
                        || ! $this->isBookableCandidate($candidate, $segment, $minimumStart, $durationMinutes, $bufferMinutes)) {
                        continue;
                    }

                    $slots[] = $this->slot($candidate, $durationMinutes, $bufferMinutes, $scheduleTimezone, $displayTimezone, $format);
                }
            }
        }

        usort($slots, fn (AvailabilitySlot $left, AvailabilitySlot $right): int => $left->startsAt->getTimestamp() <=> $right->startsAt->getTimestamp());

        $unique = [];

        foreach ($slots as $slot) {
            $unique[$slot->startsAt->getTimestamp()] = $slot;
        }

        return array_values($unique);
    }

    /**
     * @param  list<InstantInterval>  $intervals
     * @return list<InstantInterval>
     */
    private function mergeIntervals(array $intervals): array
    {
        usort($intervals, fn (InstantInterval $left, InstantInterval $right): int => $left->start->getTimestamp() <=> $right->start->getTimestamp());
        $merged = [];

        foreach ($intervals as $interval) {
            $lastKey = array_key_last($merged);
            $last = $lastKey === null ? null : $merged[$lastKey];

            if (! $last instanceof InstantInterval || $interval->start->greaterThan($last->end)) {
                $merged[] = $interval;

                continue;
            }

            if ($interval->end->greaterThan($last->end)) {
                $merged[$lastKey] = InstantInterval::from($last->start, $interval->end);
            }
        }

        return $merged;
    }

    /**
     * @param  list<InstantInterval>  $blockedIntervals
     * @return list<InstantInterval>
     */
    private function subtract(InstantInterval $base, array $blockedIntervals): array
    {
        $segments = [$base];

        foreach ($blockedIntervals as $blocked) {
            $next = [];

            foreach ($segments as $segment) {
                if (! $segment->overlaps($blocked)) {
                    $next[] = $segment;

                    continue;
                }

                if ($segment->start->lessThan($blocked->start)) {
                    $next[] = InstantInterval::from($segment->start, $blocked->start->min($segment->end));
                }

                if ($segment->end->greaterThan($blocked->end)) {
                    $next[] = InstantInterval::from($blocked->end->max($segment->start), $segment->end);
                }
            }

            $segments = $next;

            if ($segments === []) {
                break;
            }
        }

        return $segments;
    }

    private function isBookableCandidate(
        CarbonImmutable $candidate,
        InstantInterval $segment,
        CarbonImmutable $minimumStart,
        int $durationMinutes,
        int $bufferMinutes,
    ): bool {
        $blockingEnd = $candidate->addMinutes($durationMinutes + $bufferMinutes);

        return ! $candidate->lessThan($segment->start)
            && ! $blockingEnd->greaterThan($segment->end)
            && ! $candidate->lessThan($minimumStart);
    }

    private function toInstantInterval(
        LocalDate $date,
        string $timezone,
        WallClockInterval $interval,
    ): ?InstantInterval {
        $start = $this->localInstant($date, $timezone, $interval->startMinutes());
        $end = $this->localInstant($date, $timezone, $interval->endMinutes());

        if (! $start instanceof CarbonImmutable || ! $end instanceof CarbonImmutable || $start->greaterThanOrEqualTo($end)) {
            return null;
        }

        return InstantInterval::from($start, $end);
    }

    private function localInstant(LocalDate $date, string $timezone, int $minute): ?CarbonImmutable
    {
        [$year, $month, $day] = array_map('intval', explode('-', $date->value));

        try {
            return CarbonImmutable::createSafe(
                $year,
                $month,
                $day,
                intdiv($minute, 60),
                $minute % 60,
                0,
                new DateTimeZone($timezone),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function slot(
        CarbonImmutable $startsAt,
        int $durationMinutes,
        int $bufferMinutes,
        string $scheduleTimezone,
        string $displayTimezone,
        VisitFormat $format,
    ): AvailabilitySlot {
        return new AvailabilitySlot(
            startsAt: $startsAt->utc(),
            endsAt: $startsAt->addMinutes($durationMinutes)->utc(),
            blockingEndsAt: $startsAt->addMinutes($durationMinutes + $bufferMinutes)->utc(),
            scheduleTimezone: $scheduleTimezone,
            displayTimezone: $displayTimezone,
            format: $format,
        );
    }
}
