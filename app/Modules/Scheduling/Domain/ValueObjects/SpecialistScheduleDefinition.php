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
            return [
                $left->weekday,
                $left->interval->startMinutes(),
                $left->interval->endMinutes(),
                $left->startsOn?->value ?? '',
                $left->endsOn?->value ?? '9999-12-31',
            ] <=> [
                $right->weekday,
                $right->interval->startMinutes(),
                $right->interval->endMinutes(),
                $right->startsOn?->value ?? '',
                $right->endsOn?->value ?? '9999-12-31',
            ];
        });

        foreach ($intervals as $index => $interval) {
            foreach (array_slice($intervals, 0, $index) as $previous) {
                if ($interval->overlaps($previous)) {
                    throw new InvalidArgumentException('Рабочие интервалы не должны пересекаться.');
                }
            }
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

    /** @return list<WorkingHourInterval> */
    public function forDate(LocalDate|string $date): array
    {
        $date = $date instanceof LocalDate ? $date : LocalDate::from($date);

        return array_values(array_filter(
            $this->intervals,
            fn (WorkingHourInterval $interval): bool => $interval->weekday === $date->weekday()
                && $interval->appliesOn($date),
        ));
    }

    /** @return list<array{weekday: int, start_time: string, end_time: string, starts_on: string|null, ends_on: string|null}> */
    public function attributes(): array
    {
        return array_map(
            fn (WorkingHourInterval $interval): array => $interval->attributes(),
            $this->intervals,
        );
    }
}
