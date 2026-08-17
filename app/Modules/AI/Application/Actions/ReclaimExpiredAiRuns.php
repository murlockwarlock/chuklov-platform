<?php

namespace App\Modules\AI\Application\Actions;

use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use App\Modules\AI\Infrastructure\Jobs\ProcessAiRunJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReclaimExpiredAiRuns
{
    public const int MAX_BATCH_SIZE = 100;

    public function __construct(
        private readonly ReconcileExpiredAiRun $reconciler,
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
                $deadline = $run->execution_deadline_at ?? $run->created_at?->copy()->addSeconds(AiRuntimeLimits::wholeRunSeconds());
                if ($deadline === null) {
                    $deadline = $now->copy()->addSeconds(AiRuntimeLimits::wholeRunSeconds());
                }

                if ($deadline->isPast()) {
                    $this->reconciler->handle($run, 'Whole-run execution deadline expired and was reconciled.');
                    $run->update([
                        'status' => AiRunStatus::TimedOut,
                        'execution_deadline_at' => $deadline,
                        'worker_lease_expires_at' => $now,
                        'finished_at' => $now,
                        'error_message_sanitized' => 'Whole-run execution deadline expired.',
                    ]);

                    continue;
                }

                if ($run->worker_lease_token !== null) {
                    $this->reconciler->handle($run, 'Expired worker lease was reconciled before reassignment.');
                }

                $newLeaseToken = (string) Str::uuid();
                $run->update([
                    'status' => AiRunStatus::Queued,
                    'worker_lease_token' => $newLeaseToken,
                    'execution_deadline_at' => $deadline,
                    'worker_lease_expires_at' => $deadline->copy()->addSeconds(AiRuntimeLimits::PLATFORM_LEASE_GRACE_SECONDS),
                    'queued_at' => $now,
                ]);

                $claims[] = [
                    'organization_id' => (int) $run->organization_id,
                    'run_id' => (int) $run->id,
                ];
            }

            return $claims;
        });

        foreach ($claims as $claim) {
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
