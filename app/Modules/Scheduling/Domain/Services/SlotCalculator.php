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
     * @param  list<InstantInterval>  $allowedIntervals
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
        array $allowedIntervals = [],
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
                foreach ($this->candidateStartMinutes($date, $scheduleTimezone, $wallInterval, $allowedIntervals) as $startMinute) {
                    for ($minute = $startMinute; $minute <= $wallInterval->endMinutes(); $minute += $stepMinutes) {
                        if ($minute > 1439) {
                            break;
                        }

                        foreach ($this->localInstants($date, $scheduleTimezone, $minute) as $candidate) {
                            if (! $this->isBookableCandidate($candidate, $segment, $minimumStart, $durationMinutes, $bufferMinutes, $allowedIntervals)) {
                                continue;
                            }

                            $slots[] = $this->slot($candidate, $durationMinutes, $bufferMinutes, $scheduleTimezone, $displayTimezone, $format);
                        }
                    }
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

    public function wallClockInterval(
        LocalDate $date,
        string $timezone,
        WallClockInterval $interval,
    ): ?InstantInterval {
        return $this->toInstantInterval($date, $timezone, $interval);
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

    /** @param list<InstantInterval> $allowedIntervals */
    private function isBookableCandidate(
        CarbonImmutable $candidate,
        InstantInterval $segment,
        CarbonImmutable $minimumStart,
        int $durationMinutes,
        int $bufferMinutes,
        array $allowedIntervals,
    ): bool {
        $blockingEnd = $candidate->addMinutes($durationMinutes + $bufferMinutes);

        $insideAllowedInterval = $allowedIntervals === [];
        foreach ($allowedIntervals as $allowed) {
            if (! $candidate->lessThan($allowed->start) && ! $blockingEnd->greaterThan($allowed->end)) {
                $insideAllowedInterval = true;

                break;
            }
        }

        return ! $candidate->lessThan($segment->start)
            && ! $blockingEnd->greaterThan($segment->end)
            && ! $candidate->lessThan($minimumStart)
            && $insideAllowedInterval;
    }

    /**
     * @param  list<InstantInterval>  $allowedIntervals
     * @return list<int>
     */
    private function candidateStartMinutes(
        LocalDate $date,
        string $scheduleTimezone,
        WallClockInterval $wallInterval,
        array $allowedIntervals,
    ): array {
        if ($allowedIntervals === []) {
            return [$wallInterval->startMinutes()];
        }

        $starts = [];
        foreach ($allowedIntervals as $allowedInterval) {
            $localStart = $allowedInterval->start->setTimezone($scheduleTimezone);
            if ($localStart->toDateString() !== $date->value) {
                continue;
            }

            $starts[] = max(
                $wallInterval->startMinutes(),
                ($localStart->hour * 60) + $localStart->minute,
            );
        }

        sort($starts);

        return array_values(array_unique($starts));
    }

    private function toInstantInterval(
        LocalDate $date,
        string $timezone,
        WallClockInterval $interval,
    ): ?InstantInterval {
        $startInstants = $this->localInstants($date, $timezone, $interval->startMinutes());
        $endInstants = $this->localInstants($date, $timezone, $interval->endMinutes());

        if ($startInstants === [] || $endInstants === []) {
            return null;
        }

        $start = $startInstants[0];
        $end = $endInstants[count($endInstants) - 1];

        if ($start->greaterThanOrEqualTo($end)) {
            return null;
        }

        return InstantInterval::from($start, $end);
    }

    /** @return list<CarbonImmutable> */
    private function localInstants(LocalDate $date, string $timezone, int $minute): array
    {
        [$year, $month, $day] = array_map('intval', explode('-', $date->value));

        try {
            $wallTime = CarbonImmutable::createSafe(
                $year,
                $month,
                $day,
                intdiv($minute, 60),
                $minute % 60,
                0,
                new DateTimeZone('UTC'),
            );
        } catch (Throwable) {
            return [];
        }

        if (! $wallTime instanceof CarbonImmutable) {
            return [];
        }

        $wallTimestamp = $wallTime->getTimestamp();
        $windowStart = $wallTimestamp - 172800;
        $windowEnd = $wallTimestamp + 172800;

        try {
            $transitions = (new DateTimeZone($timezone))->getTransitions($windowStart, $windowEnd);
        } catch (Throwable) {
            return [];
        }

        if ($transitions === []) {
            return [];
        }

        $instants = [];
        $transitionCount = count($transitions);
        foreach ($transitions as $index => $transition) {
            $segmentStart = max($windowStart, (int) $transition['ts']);
            $segmentEnd = $index + 1 < $transitionCount
                ? min($windowEnd, (int) $transitions[$index + 1]['ts'])
                : $windowEnd;
            $candidateTimestamp = $wallTimestamp - (int) $transition['offset'];

            if ($candidateTimestamp < $segmentStart || $candidateTimestamp >= $segmentEnd) {
                continue;
            }

            $instants[$candidateTimestamp] = CarbonImmutable::createFromTimestampUTC($candidateTimestamp);
        }

        ksort($instants, SORT_NUMERIC);

        return array_values($instants);
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
