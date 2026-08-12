<?php

namespace App\Modules\Scheduling\Domain\ValueObjects;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class InstantInterval
{
    private function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    public static function from(DateTimeInterface $start, DateTimeInterface $end): self
    {
        $start = CarbonImmutable::instance($start)->utc();
        $end = CarbonImmutable::instance($end)->utc();

        if ($start->greaterThanOrEqualTo($end)) {
            throw new InvalidArgumentException('The instant interval must have a start before its end.');
        }

        return new self($start, $end);
    }

    public function overlaps(self $other): bool
    {
        return $this->start->lessThan($other->end) && $this->end->greaterThan($other->start);
    }
}
