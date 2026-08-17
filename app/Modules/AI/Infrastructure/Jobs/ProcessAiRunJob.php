<?php

namespace App\Modules\AI\Infrastructure\Jobs;

use App\Modules\AI\Domain\Contracts\AiSafetyBudgetManagerInterface;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\BudgetReservationStatus;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunAttempt;
use App\Modules\AI\Domain\Models\AiRunToolCall;
use App\Modules\AI\Domain\Registry\AiCapabilityRegistry;
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

    public int $timeout = AiRuntimeLimits::PLATFORM_MAX_TIMEOUT_SECONDS + 60;

    public function __construct(
        public int $organizationId,
        public int $runId,
    ) {}

    public function handle(AiWorkflowEngine $engine, AiSafetyBudgetManagerInterface $budgetManager): void
    {
        $newLeaseToken = (string) Str::uuid();

        /** @var array{claimed: bool, stale_attempt_ids: list<int>, old_lease_token: string|null} $claim */
        $claim = DB::transaction(function () use ($newLeaseToken): array {
            /** @var AiRun|null $run */
            $run = AiRun::query()
                ->where('organization_id', $this->organizationId)
                ->whereKey($this->runId)
                ->lockForUpdate()
                ->first();

            if ($run === null || $run->status->isTerminal()) {
                return ['claimed' => false, 'stale_attempt_ids' => [], 'old_lease_token' => null];
            }

            if ($run->status === AiRunStatus::Running
                && $run->worker_lease_expires_at !== null
                && ! $run->worker_lease_expires_at->isPast()) {
                return ['claimed' => false, 'stale_attempt_ids' => [], 'old_lease_token' => null];
            }

            $staleAttemptIds = [];
            $oldLeaseToken = $run->worker_lease_token;
            if ($run->status === AiRunStatus::Running
                && $run->worker_lease_expires_at !== null
                && $run->worker_lease_expires_at->isPast()) {
                $staleAttemptIds = AiRunAttempt::query()
                    ->where('organization_id', $this->organizationId)
                    ->where('ai_run_id', $this->runId)
                    ->where('budget_reservation_status', BudgetReservationStatus::Reserved)
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();
            }

            $capability = AiCapabilityRegistry::get($run->capability);
            $controls = AiOrganizationSafetyControl::query()
                ->where('organization_id', $this->organizationId)
                ->first();
            $timeout = AiRuntimeLimits::effectiveTimeout(
                requestedTimeout: $capability->maxTimeoutSeconds,
                capabilityMaxTimeout: $capability->maxTimeoutSeconds,
                organizationTimeout: $controls?->default_timeout_seconds,
            );
            $leaseTtl = $timeout + max(30, (int) round($timeout * 0.5));

            $run->update([
                'status' => AiRunStatus::Running,
                'worker_lease_token' => $newLeaseToken,
                'worker_lease_expires_at' => Carbon::now()->addSeconds($leaseTtl),
                'started_at' => Carbon::now(),
            ]);

            return [
                'claimed' => true,
                'stale_attempt_ids' => $staleAttemptIds,
                'old_lease_token' => $oldLeaseToken,
            ];
        });

        if (! $claim['claimed']) {
            return;
        }

        foreach ($claim['stale_attempt_ids'] as $attemptId) {
            $attempt = AiRunAttempt::query()
                ->where('organization_id', $this->organizationId)
                ->whereKey($attemptId)
                ->first();

            if ($attempt === null) {
                continue;
            }

            $budgetManager->chargeAttemptConservatively($attempt);

            $query = AiRunAttempt::query()
                ->where('organization_id', $this->organizationId)
                ->whereKey($attemptId)
                ->where('ai_run_id', $this->runId)
                ->where('status', 'running');
            if ($claim['old_lease_token'] !== null) {
                $query->where(function ($nested) use ($claim): void {
                    $nested
                        ->where('worker_lease_token', $claim['old_lease_token'])
                        ->orWhereNull('worker_lease_token');
                });
            }
            $query->update([
                'status' => 'failed',
                'retry_or_failover_reason' => 'Lease expired and reclaimed by new worker.',
                'finished_at' => Carbon::now(),
            ]);
        }

        AiRunToolCall::query()
            ->where('organization_id', $this->organizationId)
            ->where('ai_run_id', $this->runId)
            ->where('worker_lease_token', $claim['old_lease_token'])
            ->where('execution_status', 'running')
            ->update([
                'execution_status' => 'failed',
                'error_sanitized' => 'Worker lease expired before tool completion.',
                'updated_at' => Carbon::now(),
            ]);

        $engine->executeRun(
            organizationId: $this->organizationId,
            runId: $this->runId,
            workerLeaseToken: $newLeaseToken,
        );
    }
}
