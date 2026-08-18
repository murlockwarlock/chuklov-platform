<?php

namespace App\Modules\AI\Infrastructure\Jobs;

use App\Modules\AI\Application\Actions\ReconcileExpiredAiRun;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcessAiRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = AiRuntimeLimits::PLATFORM_QUEUE_JOB_TIMEOUT_SECONDS;

    public function __construct(
        public int $organizationId,
        public int $runId,
    ) {}

    public function handle(AiWorkflowEngine $engine, ReconcileExpiredAiRun $reconciler): void
    {
        $newLeaseToken = (string) Str::uuid();

        /** @var array{claimed: bool, expired: bool} $claim */
        $claim = DB::transaction(function () use ($newLeaseToken, $reconciler): array {
            /** @var AiRun|null $run */
            $run = AiRun::query()
                ->where('organization_id', $this->organizationId)
                ->whereKey($this->runId)
                ->lockForUpdate()
                ->first();

            if ($run === null || $run->status->isTerminal() || $run->status === AiRunStatus::Preparing) {
                return ['claimed' => false, 'expired' => false];
            }

            if ($run->status !== AiRunStatus::Queued
                && $run->status !== AiRunStatus::Running) {
                return ['claimed' => false, 'expired' => false];
            }

            if ($run->status === AiRunStatus::Running
                && $run->worker_lease_expires_at !== null
                && ! $run->worker_lease_expires_at->isPast()) {
                return ['claimed' => false, 'expired' => false];
            }

            $deadline = $run->execution_deadline_at
                ?? $run->created_at?->copy()->addSeconds(AiRuntimeLimits::wholeRunSeconds());
            if ($deadline === null) {
                $deadline = Carbon::now()->addSeconds(AiRuntimeLimits::wholeRunSeconds());
            }

            if (! AiRuntimeLimits::deadlineIsActive($deadline)) {
                $reconciler->handle($run, 'Whole-run execution deadline expired and was reconciled.');
                $run->update([
                    'status' => AiRunStatus::TimedOut,
                    'execution_deadline_at' => $deadline,
                    'worker_lease_expires_at' => Carbon::now(),
                    'finished_at' => Carbon::now(),
                    'error_message_sanitized' => 'Whole-run execution deadline expired.',
                ]);

                return ['claimed' => false, 'expired' => true];
            }

            if ($run->worker_lease_token !== null) {
                $reconciler->handle($run, 'Expired worker lease was reconciled before reassignment.');
            }

            $run->update([
                'status' => AiRunStatus::Running,
                'worker_lease_token' => $newLeaseToken,
                'execution_deadline_at' => $deadline,
                'worker_lease_expires_at' => $deadline->copy()->addSeconds(AiRuntimeLimits::PLATFORM_LEASE_GRACE_SECONDS),
                'started_at' => Carbon::now(),
            ]);

            return ['claimed' => true, 'expired' => false];
        });

        if (! $claim['claimed']) {
            return;
        }

        $engine->executeRun(
            organizationId: $this->organizationId,
            runId: $this->runId,
            workerLeaseToken: $newLeaseToken,
        );
    }
}
