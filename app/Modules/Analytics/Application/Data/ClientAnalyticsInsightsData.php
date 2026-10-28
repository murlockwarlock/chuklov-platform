<?php

namespace App\Modules\Analytics\Application\Data;

use Carbon\CarbonImmutable;

final readonly class ClientAnalyticsInsightsData
{
    /** @param list<string> $segments
     * @param  list<array{name: string, visit_count: int}>  $services
     */
    public function __construct(
        public array $segments,
        public int $bookingCount,
        public int $completedVisitCount,
        public int $noShowCount,
        public int $upcomingBookingCount,
        public ?CarbonImmutable $lastCompletedAt,
        public array $services,
    ) {}
}
