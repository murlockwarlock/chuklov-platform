<?php

namespace App\Modules\Tracker\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Tracker\Domain\Enums\TrackerTaskEntryStatus;
use App\Modules\Tracker\Domain\Models\TrackerTask;
use App\Modules\Tracker\Domain\Models\TrackerTaskEntry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordTrackerTaskEntry
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly ResolveTrackerAccess $access,
        private readonly TrackerTaskSchedule $schedule,
    ) {}

    public function handle(
        Client $client,
        int $taskId,
        TrackerTaskEntryStatus $status,
        ?string $comment = null,
    ): TrackerTaskEntry {
        if ((int) $client->organization_id !== $this->context->id()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }
        if (! $this->access->handle($client)->allowed()) {
            throw new AuthorizationException('Tracker access is required.');
        }

        $comment = $comment === null ? null : trim($comment);
        if ($comment !== null && mb_strlen($comment) > 500) {
            throw ValidationException::withMessages(['comment' => 'Комментарий должен быть не длиннее 500 символов.']);
        }

        return DB::transaction(function () use ($client, $taskId, $status, $comment): TrackerTaskEntry {
            $task = TrackerTask::query()
                ->where('organization_id', $this->context->id())
                ->where('client_id', $client->getKey())
                ->whereKey($taskId)
                ->where('active', true)
                ->lockForUpdate()
                ->firstOrFail();
            $today = $this->schedule->localDate($client);
            if (! $this->schedule->isDue($task, $today)) {
                throw ValidationException::withMessages(['task' => 'Эта задача сейчас недоступна для отметки.']);
            }

            $entry = TrackerTaskEntry::query()
                ->where('organization_id', $this->context->id())
                ->where('tracker_task_id', $task->getKey())
                ->where('client_id', $client->getKey())
                ->whereDate('period_start', $this->schedule->periodStart($task, $today))
                ->lockForUpdate()
                ->first();
            if (! $entry instanceof TrackerTaskEntry) {
                $entry = new TrackerTaskEntry;
                $entry->forceFill([
                    'organization_id' => $this->context->id(),
                    'tracker_task_id' => $task->getKey(),
                    'client_id' => $client->getKey(),
                    'period_start' => $this->schedule->periodStart($task, $today)->toDateString(),
                ]);
            }
            $entry->forceFill([
                'status' => $status->value,
                'comment' => $comment === '' ? null : $comment,
                'recorded_at' => now('UTC'),
                'source' => 'portal',
            ])->save();

            return $entry->refresh();
        });
    }
}
