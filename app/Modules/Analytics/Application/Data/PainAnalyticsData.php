<?php

namespace App\Modules\Analytics\Application\Data;

final readonly class PainAnalyticsData
{
    /** @param array<int, array<string, mixed>> $problems */
    public function __construct(
        public int $clientCount,
        public int $sessionCount,
        public array $problems,
        public ?float $averageBefore = null,
        public ?float $averageAfter = null,
        public ?float $averageReduction = null,
        public ?float $averageReductionPercent = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'client_count' => $this->clientCount,
            'session_count' => $this->sessionCount,
            'problems' => $this->problems,
            'average_before' => $this->averageBefore,
            'average_after' => $this->averageAfter,
            'average_reduction' => $this->averageReduction,
            'average_reduction_percent' => $this->averageReductionPercent,
        ];
    }
}
