<?php

namespace App\Modules\Scheduling\Application\Data;

use App\Modules\Scheduling\Domain\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final readonly class DashboardUpcomingDay
{
    /**
     * @param  Collection<int, Booking>  $bookings
     */
    public function __construct(
        public CarbonImmutable $date,
        public string $label,
        public bool $isToday,
        public bool $isTomorrow,
        public int $totalCount,
        public Collection $bookings,
    ) {}
}
