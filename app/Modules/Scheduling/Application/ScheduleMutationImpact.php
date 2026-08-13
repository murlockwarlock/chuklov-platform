<?php

namespace App\Modules\Scheduling\Application;

final readonly class ScheduleMutationImpact
{
    /** @param list<int> $bookingIds */
    public function __construct(public array $bookingIds) {}

    public function count(): int
    {
        return count($this->bookingIds);
    }

    public function hasConflicts(): bool
    {
        return $this->bookingIds !== [];
    }

    /** @return array{count: int, bookingIds: list<int>} */
    public function toArray(): array
    {
        return ['count' => $this->count(), 'bookingIds' => $this->bookingIds];
    }
}
