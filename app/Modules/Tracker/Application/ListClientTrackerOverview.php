<?php

namespace App\Modules\Tracker\Application;

use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Tracker\Domain\Models\TrackerCheckIn;
use App\Modules\Tracker\Domain\Models\TrackerTask;
use App\Modules\Tracker\Domain\Models\TrackerTaskEntry;
use Carbon\CarbonImmutable;

final class ListClientTrackerOverview
{
    public function __construct(
        private readonly ClientPortalContext $clientContext,
        private readonly ResolveTrackerAccess $access,
        private readonly TrackerTaskSchedule $schedule,
    ) {}

    /** @return array<string, mixed> */
    public function handle(): array
    {
        $client = $this->clientContext->client();
        $state = $this->access->handle($client);
        $today = $this->schedule->localDate($client);
        $tasks = TrackerTask::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->where('active', true)
            ->whereDate('starts_on', '<=', $today)
            ->where(function ($query) use ($today): void {
                $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today);
            })
            ->with(['entries' => function ($query) use ($today): void {
                $query->whereDate('period_start', '>=', $today->subDays(7))
                    ->whereDate('period_start', '<=', $today);
            }])
            ->orderBy('display_order')
            ->orderBy('id')
            ->limit(100)
            ->get();
        $todayTasks = $tasks
            ->filter(fn (TrackerTask $task): bool => $this->schedule->isDue($task, $today))
            ->map(fn (TrackerTask $task): array => $this->task($task, $today))
            ->values()
            ->all();
        $history = $state->allowed()
            ? TrackerTaskEntry::query()
                ->where('organization_id', $client->organization_id)
                ->where('client_id', $client->getKey())
                ->with('task')
                ->latest('period_start')
                ->latest('recorded_at')
                ->limit(50)
                ->get()
                ->map(fn (TrackerTaskEntry $entry): array => $this->historyEntry($entry))
                ->values()
                ->all()
            : [];
        $checkIns = $state->allowed()
            ? TrackerCheckIn::query()
                ->where('organization_id', $client->organization_id)
                ->where('client_id', $client->getKey())
                ->latest('occurred_at')
                ->limit(30)
                ->get()
                ->map(fn (TrackerCheckIn $entry): array => [
                    'occurredAt' => CarbonImmutable::parse((string) $entry->getRawOriginal('occurred_at'))->toIso8601String(),
                    'note' => (string) $entry->note,
                ])
                ->all()
            : [];

        return [
            'access' => $state->toClientArray(),
            'today' => $state->allowed() ? $todayTasks : [],
            'program' => $state->allowed() ? $tasks->map(fn (TrackerTask $task): array => $this->programTask($task))->values()->all() : [],
            'history' => $history,
            'checkIns' => $checkIns,
            'monthlyPractice' => $state->allowed() ? $state->monthlyPractice() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function task(TrackerTask $task, CarbonImmutable $today): array
    {
        $periodStart = $this->schedule->periodStart($task, $today);
        $entry = $task->entries->first(fn (TrackerTaskEntry $entry): bool => $entry->period_start->isSameDay($periodStart));

        return [
            'id' => (int) $task->getKey(),
            'title' => $task->title,
            'type' => $task->task_type->value,
            'frequency' => $task->frequency->value,
            'weekDay' => $task->week_day,
            'periodStart' => $periodStart->toDateString(),
            'status' => $entry?->status->value ?? 'pending',
            'comment' => $entry?->comment,
        ];
    }

    /** @return array<string, mixed> */
    private function programTask(TrackerTask $task): array
    {
        return [
            'id' => (int) $task->getKey(),
            'title' => $task->title,
            'type' => $task->task_type->value,
            'frequency' => $task->frequency->value,
            'weekDay' => $task->week_day,
            'startsOn' => $task->starts_on->toDateString(),
            'endsOn' => $task->ends_on?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function historyEntry(TrackerTaskEntry $entry): array
    {
        return [
            'taskId' => (int) $entry->tracker_task_id,
            'title' => $entry->task?->title,
            'type' => $entry->task?->task_type?->value,
            'periodStart' => $entry->period_start->toDateString(),
            'status' => $entry->status->value,
            'comment' => $entry->comment,
            'recordedAt' => $entry->recorded_at->toIso8601String(),
        ];
    }
}
