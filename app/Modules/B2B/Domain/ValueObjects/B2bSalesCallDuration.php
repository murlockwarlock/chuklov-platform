<?php

namespace App\Modules\B2B\Domain\ValueObjects;

use DateTimeInterface;
use InvalidArgumentException;

final readonly class B2bSalesCallDuration
{
    private function __construct(public int $minutes) {}

    public static function between(DateTimeInterface $startsAt, DateTimeInterface $endsAt): self
    {
        if (! self::isWholeMinute($startsAt) || ! self::isWholeMinute($endsAt)) {
            throw new InvalidArgumentException('The B2B sales-call interval must use whole minutes.');
        }

        $elapsedSeconds = $endsAt->getTimestamp() - $startsAt->getTimestamp();
        if ($elapsedSeconds <= 0 || $elapsedSeconds % 60 !== 0) {
            throw new InvalidArgumentException('The B2B sales-call interval must be a positive whole number of minutes.');
        }

        return new self(intdiv($elapsedSeconds, 60));
    }

    private static function isWholeMinute(DateTimeInterface $value): bool
    {
        return (int) $value->format('s') === 0 && (int) $value->format('u') === 0;
    }
}
