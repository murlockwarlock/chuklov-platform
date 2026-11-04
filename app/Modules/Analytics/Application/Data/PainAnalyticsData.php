<?php

namespace App\Modules\Analytics\Application\Data;

final readonly class PainAnalyticsData
{
    /** @param array<int, array<string, mixed>> $problems */
    public function __construct(
        public int $clientCount,
        public int $sessionCount,
        public array $problems,
        public int $beforeScoreCount = 0,
        public int $afterScoreCount = 0,
        public ?float $averageBefore = null,
        public ?float $averageAfter = null,
        public ?float $averageReduction = null,
        public ?float $averageReductionPercent = null,
        public string $sessionAxisLabel = 'Порядок сеансов конкретной категории проблемы',
        public string $sessionAxisVersion = 'problem-chronological-v1',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'client_count' => $this->clientCount,
            'session_count' => $this->sessionCount,
            'before_score_count' => $this->beforeScoreCount,
            'after_score_count' => $this->afterScoreCount,
            'problems' => $this->problems,
            'average_before' => $this->averageBefore,
            'average_after' => $this->averageAfter,
            'average_reduction' => $this->averageReduction,
            'average_reduction_percent' => $this->averageReductionPercent,
            'session_axis_label' => $this->sessionAxisLabel,
            'session_axis_version' => $this->sessionAxisVersion,
        ];
    }
}
