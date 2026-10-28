<?php

namespace App\Modules\Tracker\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Tracker\Domain\Enums\TrackerTaskEntryStatus;
use App\Modules\Tracker\Domain\Models\TrackerTask;
use App\Modules\Tracker\Domain\Models\TrackerTaskEntry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class RecordTrackerTaskEntry
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly ResolveTrackerAccess $access,
        private readonly TrackerTaskSchedule $schedule,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        Client $client,
        int $taskId,
        TrackerTaskEntryStatus $status,
        ?string $comment = null,
    ): TrackerTaskEntry {
        if ((int) $client->organization_id !== $this->context->id()) {
            $this->logDenied($client, $taskId, 'organization_mismatch');

            throw new AuthorizationException('The client is outside the current organization.');
        }
        if (! $this->access->handle($client)->allowed()) {
            $this->logDenied($client, $taskId, 'tracker_access_required');

            throw new AuthorizationException('Tracker access is required.');
        }

        $comment = $comment === null ? null : trim($comment);
        if ($comment !== null && mb_strlen($comment) > 500) {
            throw ValidationException::withMessages(['comment' => 'Комментарий должен быть не длиннее 500 символов.']);
        }

        return DB::transaction(function () use ($client, $taskId, $status, $comment): TrackerTaskEntry {
            try {
                $task = TrackerTask::query()
                    ->where('organization_id', $this->context->id())
                    ->where('client_id', $client->getKey())
                    ->whereKey($taskId)
                    ->where('active', true)
                    ->lockForUpdate()
                    ->firstOrFail();
            } catch (ModelNotFoundException $exception) {
                $this->logDenied($client, $taskId, 'task_unavailable');

                throw $exception;
            }
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
            $previousStatus = $entry?->status->value;
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

            $this->audit->handle(
                $this->context->organization(),
                null,
                'tracker.task_entry.updated',
                TrackerTaskEntry::class,
                (string) $entry->getKey(),
                [
                    'actor_client_id' => $client->getKey(),
                    'task_id' => $task->getKey(),
                    'previous_status' => $previousStatus,
                    'status' => $status->value,
                    'period_start' => $entry->period_start->toDateString(),
                ],
            );

            return $entry->refresh();
        });
    }

    private function logDenied(Client $client, int $taskId, string $safeErrorCode): void
    {
        Log::warning('portal.tracker.task_entry.denied', [
            'organization_id' => $this->context->id(),
            'actor_client_id' => $client->getKey(),
            'entity_type' => TrackerTask::class,
            'entity_id' => $taskId,
            'safe_error_code' => $safeErrorCode,
            'outcome' => 'denied',
        ]);
    }
}
