<?php

namespace App\Modules\Analytics\Application\Data;

final readonly class GoalAnalyticsData
{
    /** @param list<array{name: string, group: string|null, client_count: int, task_count: int}> $goals */
    public function __construct(public array $goals) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['goals' => $this->goals];
    }
}
