<?php

namespace App\Modules\AI\Application\Actions;

use App\Modules\AI\Domain\Contracts\AiSafetyBudgetManagerInterface;
use App\Modules\AI\Domain\Enums\BudgetReservationStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunAttempt;
use App\Modules\AI\Domain\Models\AiRunToolCall;
use Illuminate\Support\Carbon;

final class ReconcileExpiredAiRun
{
    public function __construct(
        private readonly AiSafetyBudgetManagerInterface $budgetManager,
    ) {}

    public function handle(AiRun $run, string $reason): void
    {
        $attempts = AiRunAttempt::query()
            ->where('organization_id', $run->organization_id)
            ->where('ai_run_id', $run->id)
            ->where('budget_reservation_status', BudgetReservationStatus::Reserved)
            ->lockForUpdate()
            ->get();

        foreach ($attempts as $attempt) {
            $this->budgetManager->chargeAttemptConservatively($attempt);

            AiRunAttempt::query()
                ->where('organization_id', $run->organization_id)
                ->whereKey($attempt->id)
                ->where('ai_run_id', $run->id)
                ->where('status', 'running')
                ->update([
                    'status' => 'failed',
                    'retry_or_failover_reason' => $reason,
                    'finished_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
        }

        if ($run->worker_lease_token === null) {
            return;
        }

        AiRunToolCall::query()
            ->where('organization_id', $run->organization_id)
            ->where('ai_run_id', $run->id)
            ->where('worker_lease_token', $run->worker_lease_token)
            ->where('execution_status', 'running')
            ->update([
                'execution_status' => 'failed',
                'error_sanitized' => 'Worker lease expired before tool completion.',
                'updated_at' => Carbon::now(),
            ]);
    }
}
