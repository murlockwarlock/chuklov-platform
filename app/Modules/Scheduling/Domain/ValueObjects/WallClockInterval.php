<?php

namespace App\Modules\Scheduling\Domain\ValueObjects;

use DateTimeInterface;
use InvalidArgumentException;

final readonly class WallClockInterval
{
    private function __construct(
        public string $start,
        public string $end,
    ) {}

    public static function from(mixed $start, mixed $end): self
    {
        $normalizedStart = self::normalize($start);
        $normalizedEnd = self::normalize($end);

        if (self::minutes($normalizedStart) >= self::minutes($normalizedEnd)) {
            throw new InvalidArgumentException('The schedule interval must have a start before its end.');
        }

        return new self($normalizedStart, $normalizedEnd);
    }

    public function startMinutes(): int
    {
        return self::minutes($this->start);
    }

    public function endMinutes(): int
    {
        return self::minutes($this->end);
    }

    private static function normalize(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i');
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('The schedule time is invalid.');
        }

        $value = trim($value);

        if (preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/', $value) !== 1) {
            throw new InvalidArgumentException('The schedule time must use the HH:MM format.');
        }

        return substr($value, 0, 5);
    }

    private static function minutes(string $value): int
    {
        return ((int) substr($value, 0, 2) * 60) + (int) substr($value, 3, 2);
    }
}
