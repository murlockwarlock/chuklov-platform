<?php

namespace App\Modules\Scheduling\Application\Data;

final readonly class DashboardUpcomingBookingsResult
{
    /**
     * @param  array<int, DashboardUpcomingDay>  $days
     */
    public function __construct(
        public int $todayCount,
        public int $tomorrowCount,
        public array $days,
        public string $timezone,
    ) {}
}
