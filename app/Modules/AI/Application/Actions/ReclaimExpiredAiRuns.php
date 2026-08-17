<?php

namespace App\Modules\AI\Application\Actions;

use App\Modules\AI\Domain\Contracts\AiSafetyBudgetManagerInterface;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\BudgetReservationStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunAttempt;
use App\Modules\AI\Domain\Models\AiRunToolCall;
use App\Modules\AI\Infrastructure\Jobs\ProcessAiRunJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReclaimExpiredAiRuns
{
    public const int MAX_BATCH_SIZE = 100;

    public function __construct(
        private readonly AiSafetyBudgetManagerInterface $budgetManager,
    ) {}

    /** @return array{reclaimed: int, dispatched: int} */
    public function handle(?int $requestedBatchSize = null): array
    {
        $batchSize = min(self::MAX_BATCH_SIZE, max(1, $requestedBatchSize ?? self::MAX_BATCH_SIZE));
        $now = Carbon::now();

        $claims = DB::transaction(function () use ($batchSize, $now): array {
            $query = AiRun::query()
                ->whereIn('status', [AiRunStatus::Queued, AiRunStatus::Running])
                ->whereNotNull('worker_lease_expires_at')
                ->where('worker_lease_expires_at', '<=', $now)
                ->orderBy('id')
                ->limit($batchSize);

            $runs = DB::getDriverName() === 'pgsql'
                ? $query->lock('FOR UPDATE SKIP LOCKED')->get()
                : $query->lockForUpdate()->get();
            $claims = [];

            foreach ($runs as $run) {
                $newLeaseToken = (string) Str::uuid();
                $staleAttemptIds = AiRunAttempt::query()
                    ->where('organization_id', $run->organization_id)
                    ->where('ai_run_id', $run->id)
                    ->where('budget_reservation_status', BudgetReservationStatus::Reserved)
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();

                $oldLeaseToken = $run->worker_lease_token;
                $run->update([
                    'status' => AiRunStatus::Queued,
                    'worker_lease_token' => $newLeaseToken,
                    'worker_lease_expires_at' => $now->copy()->addMinutes(5),
                    'queued_at' => $now,
                ]);

                $claims[] = [
                    'organization_id' => (int) $run->organization_id,
                    'run_id' => (int) $run->id,
                    'old_lease_token' => $oldLeaseToken,
                    'stale_attempt_ids' => $staleAttemptIds,
                ];
            }

            return $claims;
        });

        foreach ($claims as $claim) {
            foreach ($claim['stale_attempt_ids'] as $attemptId) {
                $attempt = AiRunAttempt::query()
                    ->where('organization_id', $claim['organization_id'])
                    ->whereKey($attemptId)
                    ->first();

                if ($attempt === null) {
                    continue;
                }

                $this->budgetManager->chargeAttemptConservatively($attempt);

                $query = AiRunAttempt::query()
                    ->where('organization_id', $claim['organization_id'])
                    ->whereKey($attemptId)
                    ->where('ai_run_id', $claim['run_id'])
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
                    'retry_or_failover_reason' => 'Lease expired and was reclaimed by the scheduled reclaimer.',
                    'finished_at' => Carbon::now(),
                ]);
            }

            AiRunToolCall::query()
                ->where('organization_id', $claim['organization_id'])
                ->where('ai_run_id', $claim['run_id'])
                ->where('worker_lease_token', $claim['old_lease_token'])
                ->where('execution_status', 'running')
                ->update([
                    'execution_status' => 'failed',
                    'error_sanitized' => 'Worker lease expired before tool completion.',
                    'updated_at' => Carbon::now(),
                ]);

            ProcessAiRunJob::dispatch(
                organizationId: $claim['organization_id'],
                runId: $claim['run_id'],
            );
        }

        return [
            'reclaimed' => count($claims),
            'dispatched' => count($claims),
        ];
    }
}
