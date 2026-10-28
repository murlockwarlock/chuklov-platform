<?php

namespace App\Modules\Analytics\Application\Data;

final readonly class BookingStatusAnalyticsData
{
    /** @param list<array{key: string, label: string, count: int}> $statuses */
    public function __construct(public array $statuses) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['statuses' => $this->statuses];
    }
}
