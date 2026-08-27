<?php

namespace App\Modules\Analytics\Application\Data;

final readonly class SchedulingAnalyticsData
{
    public function __construct(
        public int $bookings,
        public int $cancellations,
        public int $reschedules,
        public int $visits,
        public int $homeRequests,
        public int $retainedClients,
        public int $notRetainedClients,
    ) {}

    public function retentionDenominator(): int
    {
        return $this->retainedClients + $this->notRetainedClients;
    }

    public function retentionRate(): ?float
    {
        $denominator = $this->retentionDenominator();

        return $denominator === 0 ? null : round($this->retainedClients / $denominator * 100, 1);
    }
}
