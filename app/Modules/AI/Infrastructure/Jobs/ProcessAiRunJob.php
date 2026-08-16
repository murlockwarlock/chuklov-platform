<?php

namespace App\Modules\AI\Infrastructure\Jobs;

use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunAttempt;
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

    public int $timeout = 180;

    public function __construct(
        public int $organizationId,
        public int $runId,
    ) {}

    public function handle(AiWorkflowEngine $engine): void
    {
        $newLeaseToken = (string) Str::uuid();

        $claimed = DB::transaction(function () use ($newLeaseToken): bool {
            /** @var AiRun|null $run */
            $run = AiRun::query()
                ->where('organization_id', $this->organizationId)
                ->where('id', $this->runId)
                ->lockForUpdate()
                ->first();

            if (! $run) {
                return false;
            }

            if ($run->status->isTerminal()) {
                return false;
            }

            if ($run->status === AiRunStatus::Running && $run->worker_lease_expires_at !== null && ! $run->worker_lease_expires_at->isPast()) {
                return false;
            }

            if ($run->status === AiRunStatus::Running && $run->worker_lease_expires_at !== null && $run->worker_lease_expires_at->isPast()) {
                AiRunAttempt::query()
                    ->where('organization_id', $this->organizationId)
                    ->where('ai_run_id', $this->runId)
                    ->where('status', 'running')
                    ->update([
                        'status' => 'failed',
                        'retry_or_failover_reason' => 'Lease expired and reclaimed by new worker.',
                    ]);
            }

            $leaseTtl = 180;
            $run->update([
                'status' => AiRunStatus::Running,
                'worker_lease_token' => $newLeaseToken,
                'worker_lease_expires_at' => Carbon::now()->addSeconds($leaseTtl),
                'started_at' => Carbon::now(),
            ]);

            return true;
        });

        if (! $claimed) {
            return;
        }

        $engine->executeRun(
            organizationId: $this->organizationId,
            runId: $this->runId,
            workerLeaseToken: $newLeaseToken,
        );
    }
}
