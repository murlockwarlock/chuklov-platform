<?php

namespace App\Modules\Scheduling\Domain\ValueObjects;

use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use InvalidArgumentException;

final readonly class ScheduleExceptionDefinition
{
    private function __construct(
        public string $date,
        public ScheduleExceptionType $type,
        public ?WallClockInterval $interval,
        public ?string $reason,
    ) {}

    /** @param array<string, mixed> $attributes */
    public static function from(array $attributes): self
    {
        $date = LocalDate::from((string) ($attributes['exception_date'] ?? ''))->value;
        $type = $attributes['exception_type'] ?? null;

        if ($type instanceof ScheduleExceptionType) {
            $exceptionType = $type;
        } elseif (is_string($type)) {
            $exceptionType = ScheduleExceptionType::tryFrom($type);
        } else {
            $exceptionType = null;
        }

        if (! $exceptionType instanceof ScheduleExceptionType) {
            throw new InvalidArgumentException('The schedule exception type is invalid.');
        }

        $hasStart = array_key_exists('start_time', $attributes) && $attributes['start_time'] !== null && $attributes['start_time'] !== '';
        $hasEnd = array_key_exists('end_time', $attributes) && $attributes['end_time'] !== null && $attributes['end_time'] !== '';

        if ($exceptionType === ScheduleExceptionType::DayOff && ($hasStart || $hasEnd)) {
            throw new InvalidArgumentException('A day-off exception cannot have a time window.');
        }

        if ($exceptionType === ScheduleExceptionType::CustomWindow && (! $hasStart || ! $hasEnd)) {
            throw new InvalidArgumentException('A custom schedule exception requires a time window.');
        }

        $reason = $attributes['reason'] ?? null;

        if ($reason !== null) {
            if (! is_string($reason)) {
                throw new InvalidArgumentException('The schedule exception reason is invalid.');
            }

            $reason = trim($reason);

            if ($reason === '' || mb_strlen($reason) > 500) {
                throw new InvalidArgumentException('The schedule exception reason is invalid.');
            }
        }

        return new self(
            date: $date,
            type: $exceptionType,
            interval: $exceptionType === ScheduleExceptionType::CustomWindow
                ? WallClockInterval::from($attributes['start_time'], $attributes['end_time'])
                : null,
            reason: $reason,
        );
    }
}
