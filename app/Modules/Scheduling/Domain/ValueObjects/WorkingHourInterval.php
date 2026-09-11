<?php

namespace App\Modules\Scheduling\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class WorkingHourInterval
{
    private function __construct(
        public int $weekday,
        public WallClockInterval $interval,
        public ?LocalDate $startsOn,
        public ?LocalDate $endsOn,
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

        $startsOn = self::optionalDate($attributes['starts_on'] ?? null);
        $endsOn = self::optionalDate($attributes['ends_on'] ?? null);

        if ($startsOn !== null && $endsOn !== null && $startsOn->value > $endsOn->value) {
            throw new InvalidArgumentException('Дата окончания не может быть раньше даты начала.');
        }

        return new self(
            weekday: $weekday,
            interval: WallClockInterval::from(
                $attributes['start_time'] ?? null,
                $attributes['end_time'] ?? null,
            ),
            startsOn: $startsOn,
            endsOn: $endsOn,
        );
    }

    /** @return array{weekday: int, start_time: string, end_time: string, starts_on: string|null, ends_on: string|null} */
    public function attributes(): array
    {
        return [
            'weekday' => $this->weekday,
            'start_time' => $this->interval->start,
            'end_time' => $this->interval->end,
            'starts_on' => $this->startsOn?->value,
            'ends_on' => $this->endsOn?->value,
        ];
    }

    public function appliesOn(LocalDate|string $date): bool
    {
        $date = $date instanceof LocalDate ? $date : LocalDate::from($date);

        return ($this->startsOn === null || $date->value >= $this->startsOn->value)
            && ($this->endsOn === null || $date->value <= $this->endsOn->value);
    }

    public function overlaps(self $other): bool
    {
        if ($this->weekday !== $other->weekday || ! $this->validityOverlaps($other)) {
            return false;
        }

        return $this->interval->startMinutes() < $other->interval->endMinutes()
            && $this->interval->endMinutes() > $other->interval->startMinutes();
    }

    private function validityOverlaps(self $other): bool
    {
        if ($this->endsOn !== null && $other->startsOn !== null && $this->endsOn->value < $other->startsOn->value) {
            return false;
        }

        if ($other->endsOn !== null && $this->startsOn !== null && $other->endsOn->value < $this->startsOn->value) {
            return false;
        }

        return true;
    }

    private static function optionalDate(mixed $value): ?LocalDate
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return LocalDate::from($value->format('Y-m-d'));
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Дата действия расписания указана неверно.');
        }

        return LocalDate::from($value);
    }
}
