<?php

namespace App\Modules\Analytics\Application\Data;

final readonly class FeedbackAnalyticsData
{
    /** @param list<array<string, int|float|null|string>> $themes */
    public function __construct(
        public int $submissionCount,
        public ?float $averageScore,
        public array $themes,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'submission_count' => $this->submissionCount,
            'average_score' => $this->averageScore,
            'themes' => $this->themes,
        ];
    }
}
