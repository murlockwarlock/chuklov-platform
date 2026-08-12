<?php

namespace App\Modules\Scheduling\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class WorkingHourInterval
{
    private function __construct(
        public int $weekday,
        public WallClockInterval $interval,
    ) {}

    /** @param array<string, mixed> $attributes */
    public static function from(array $attributes): self
    {
        $weekday = $attributes['weekday'] ?? null;

        if (is_string($weekday) && ctype_digit($weekday)) {
            $weekday = (int) $weekday;
        }

        if (! is_int($weekday) || $weekday < 1 || $weekday > 7) {
            throw new InvalidArgumentException('The schedule weekday must be between 1 and 7.');
        }

        return new self($weekday, WallClockInterval::from(
            $attributes['start_time'] ?? null,
            $attributes['end_time'] ?? null,
        ));
    }

    /** @return array{weekday: int, start_time: string, end_time: string} */
    public function attributes(): array
    {
        return [
            'weekday' => $this->weekday,
            'start_time' => $this->interval->start,
            'end_time' => $this->interval->end,
        ];
    }
}
