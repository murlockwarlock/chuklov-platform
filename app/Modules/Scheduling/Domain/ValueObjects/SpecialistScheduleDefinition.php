<?php

namespace App\Modules\Scheduling\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class SpecialistScheduleDefinition
{
    /** @param list<WorkingHourInterval> $intervals */
    private function __construct(public array $intervals) {}

    /** @param array<int, array<string, mixed>> $definitions */
    public static function from(array $definitions): self
    {
        $intervals = [];

        foreach (array_values($definitions) as $definition) {
            $intervals[] = WorkingHourInterval::from($definition);
        }

        usort($intervals, function (WorkingHourInterval $left, WorkingHourInterval $right): int {
            return [$left->weekday, $left->interval->startMinutes()]
                <=> [$right->weekday, $right->interval->startMinutes()];
        });

        $previousByWeekday = [];

        foreach ($intervals as $interval) {
            $previous = $previousByWeekday[$interval->weekday] ?? null;

            if ($previous instanceof WorkingHourInterval
                && $interval->interval->startMinutes() < $previous->interval->endMinutes()) {
                throw new InvalidArgumentException('Working-hour intervals may not overlap.');
            }

            $previousByWeekday[$interval->weekday] = $interval;
        }

        return new self($intervals);
    }

    /** @return list<WorkingHourInterval> */
    public function forWeekday(int $weekday): array
    {
        return array_values(array_filter(
            $this->intervals,
            fn (WorkingHourInterval $interval): bool => $interval->weekday === $weekday,
        ));
    }

    /** @return list<array{weekday: int, start_time: string, end_time: string}> */
    public function attributes(): array
    {
        return array_map(
            fn (WorkingHourInterval $interval): array => $interval->attributes(),
            $this->intervals,
        );
    }
}
