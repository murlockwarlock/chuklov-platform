<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventStatus;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Jobs\ExecuteScenarioAction;
use App\Modules\Scenarios\Jobs\ProcessScenarioEvent;
use Carbon\CarbonImmutable;

final class ScheduleScenarioWork
{
    /** @return array{events: int, actions: int} */
    public function handle(): array
    {
        $now = CarbonImmutable::now();
        $staleAt = $now->subSeconds((int) config('scenarios.scheduler.stale_after_seconds', 300));
        $limit = (int) config('scenarios.scheduler.batch_size', 100);
        $queue = (string) config('scenarios.queue', 'scenarios');

        $eventIds = ScenarioEvent::query()
            ->where(function ($query) use ($now, $staleAt): void {
                $query->where(function ($query) use ($now): void {
                    $query->whereIn('status', [ScenarioEventStatus::Pending->value, ScenarioEventStatus::Retryable->value])
                        ->where('available_at', '<=', $now);
                })->orWhere(function ($query) use ($staleAt): void {
                    $query->where('status', ScenarioEventStatus::Processing->value)
                        ->where('processing_started_at', '<=', $staleAt);
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($eventIds as $eventId) {
            ProcessScenarioEvent::dispatch((int) $eventId)->onQueue($queue);
        }

        $actionIds = ScenarioAction::query()
            ->where(function ($query) use ($now, $staleAt): void {
                $query->where(function ($query) use ($now): void {
                    $query->whereIn('status', [ScenarioActionStatus::Scheduled->value, ScenarioActionStatus::Retryable->value])
                        ->where('scheduled_for', '<=', $now);
                })->orWhere(function ($query) use ($staleAt): void {
                    $query->where('status', ScenarioActionStatus::Processing->value)
                        ->where('processing_started_at', '<=', $staleAt);
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($actionIds as $actionId) {
            ExecuteScenarioAction::dispatch((int) $actionId)->onQueue($queue);
        }

        return [
            'events' => $eventIds->count(),
            'actions' => $actionIds->count(),
        ];
    }
}
