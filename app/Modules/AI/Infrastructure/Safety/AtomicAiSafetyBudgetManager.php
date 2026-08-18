<?php

namespace App\Modules\AI\Infrastructure\Safety;

use App\Modules\AI\Domain\Contracts\AiSafetyBudgetManagerInterface;
use App\Modules\AI\Domain\Enums\BudgetReservationStatus;
use App\Modules\AI\Domain\Exceptions\AiBudgetExceededException;
use App\Modules\AI\Domain\Exceptions\AiKillSwitchException;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunAttempt;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AtomicAiSafetyBudgetManager implements AiSafetyBudgetManagerInterface
{
    public function reserveBudget(int $organizationId, int $requestedMinorUnits): void
    {
        if ($requestedMinorUnits < 0) {
            throw new AiBudgetExceededException('Budget reservation cannot be negative.');
        }

        $safetyControls = AiOrganizationSafetyControl::query()
            ->where('organization_id', $organizationId)
            ->first();

        if ($safetyControls !== null && ! $safetyControls->is_ai_globally_enabled) {
            throw new AiKillSwitchException('AI is globally disabled for this organization.');
        }

        $maxDailyLimit = AiRuntimeLimits::effectiveDailySpendLimit(
            $safetyControls?->max_daily_spend_minor_units,
        );

        $today = Carbon::now()->toDateString();

        DB::transaction(function () use ($organizationId, $today, $requestedMinorUnits, $maxDailyLimit) {
            DB::table('ai_organization_daily_budgets')->insertOrIgnore([
                'organization_id' => $organizationId,
                'usage_date' => $today,
                'spent_minor_units' => 0,
                'reserved_minor_units' => 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            $budget = DB::table('ai_organization_daily_budgets')
                ->where('organization_id', $organizationId)
                ->whereDate('usage_date', $today)
                ->lockForUpdate()
                ->first();

            if (! $budget) {
                throw new AiBudgetExceededException('Unable to acquire budget record lock.');
            }

            $currentTotal = (int) $budget->spent_minor_units + (int) $budget->reserved_minor_units + $requestedMinorUnits;

            if ($currentTotal > $maxDailyLimit) {
                throw new AiBudgetExceededException("Daily spend budget of {$maxDailyLimit} minor units would be exceeded (projected: {$currentTotal}).");
            }

            DB::table('ai_organization_daily_budgets')
                ->where('id', $budget->id)
                ->update([
                    'reserved_minor_units' => (int) $budget->reserved_minor_units + $requestedMinorUnits,
                    'updated_at' => Carbon::now(),
                ]);
        });
    }

    public function settleBudget(
        int $organizationId,
        string $usageDate,
        int $reservedMinorUnits,
        int $settledMinorUnits,
    ): void {
        DB::transaction(function () use ($organizationId, $usageDate, $reservedMinorUnits, $settledMinorUnits) {
            $budget = DB::table('ai_organization_daily_budgets')
                ->where('organization_id', $organizationId)
                ->whereDate('usage_date', $usageDate)
                ->lockForUpdate()
                ->first();

            if ($budget) {
                $newReserved = max(0, (int) $budget->reserved_minor_units - $reservedMinorUnits);
                $maxDailyLimit = $this->dailyLimit($organizationId);
                $remaining = max(0, $maxDailyLimit - (int) $budget->spent_minor_units);
                $newSpent = (int) $budget->spent_minor_units + min($remaining, max(0, $settledMinorUnits));

                DB::table('ai_organization_daily_budgets')
                    ->where('id', $budget->id)
                    ->update([
                        'spent_minor_units' => $newSpent,
                        'reserved_minor_units' => $newReserved,
                        'updated_at' => Carbon::now(),
                    ]);
            }
        });
    }

    public function releaseBudget(int $organizationId, string $usageDate, int $reservedMinorUnits): void
    {
        DB::transaction(function () use ($organizationId, $usageDate, $reservedMinorUnits) {
            $budget = DB::table('ai_organization_daily_budgets')
                ->where('organization_id', $organizationId)
                ->whereDate('usage_date', $usageDate)
                ->lockForUpdate()
                ->first();

            if ($budget) {
                $newReserved = max(0, (int) $budget->reserved_minor_units - $reservedMinorUnits);

                DB::table('ai_organization_daily_budgets')
                    ->where('id', $budget->id)
                    ->update([
                        'reserved_minor_units' => $newReserved,
                        'updated_at' => Carbon::now(),
                    ]);
            }
        });
    }

    public function chargeConservatively(int $organizationId, string $usageDate, int $reservedMinorUnits): void
    {
        DB::transaction(function () use ($organizationId, $usageDate, $reservedMinorUnits) {
            $budget = DB::table('ai_organization_daily_budgets')
                ->where('organization_id', $organizationId)
                ->whereDate('usage_date', $usageDate)
                ->lockForUpdate()
                ->first();

            if ($budget) {
                $newReserved = max(0, (int) $budget->reserved_minor_units - $reservedMinorUnits);
                $maxDailyLimit = $this->dailyLimit($organizationId);
                $remaining = max(0, $maxDailyLimit - (int) $budget->spent_minor_units);
                $newSpent = (int) $budget->spent_minor_units + min($remaining, max(0, $reservedMinorUnits));

                DB::table('ai_organization_daily_budgets')
                    ->where('id', $budget->id)
                    ->update([
                        'spent_minor_units' => $newSpent,
                        'reserved_minor_units' => $newReserved,
                        'updated_at' => Carbon::now(),
                    ]);
            }
        });
    }

    public function settleAttemptBudget(AiRunAttempt $attempt, int $settledMinorUnits): int
    {
        return (int) DB::transaction(function () use ($attempt, $settledMinorUnits): int {
            /** @var AiRunAttempt|null $lockedAttempt */
            $lockedAttempt = AiRunAttempt::query()
                ->where('organization_id', $attempt->organization_id)
                ->where('id', $attempt->id)
                ->lockForUpdate()
                ->first();

            if ($lockedAttempt === null || $lockedAttempt->budget_reservation_status !== BudgetReservationStatus::Reserved) {
                return 0;
            }

            $reserved = (int) $lockedAttempt->reserved_cost_minor_units;
            $usageDate = $lockedAttempt->budget_usage_date->toDateString();

            $budget = DB::table('ai_organization_daily_budgets')
                ->where('organization_id', $lockedAttempt->organization_id)
                ->whereDate('usage_date', $usageDate)
                ->lockForUpdate()
                ->first();

            $anomaly = false;
            $charge = 0;

            if ($budget) {
                $maxDailyLimit = $this->dailyLimit((int) $lockedAttempt->organization_id);
                $spent = (int) $budget->spent_minor_units;
                $actual = max(0, $settledMinorUnits);
                $anomaly = $actual > $reserved || $spent + $actual > $maxDailyLimit;
                $charge = $anomaly ? max($reserved, $actual) : $actual;
                $charge = min(max(0, $maxDailyLimit - $spent), $charge);
                $newReserved = max(0, (int) $budget->reserved_minor_units - $reserved);
                $newSpent = $spent + $charge;

                DB::table('ai_organization_daily_budgets')
                    ->where('id', $budget->id)
                    ->update([
                        'spent_minor_units' => $newSpent,
                        'reserved_minor_units' => $newReserved,
                        'updated_at' => Carbon::now(),
                    ]);
            }

            $lockedAttempt->update([
                'budget_reservation_status' => $anomaly
                    ? BudgetReservationStatus::ConservativelyCharged
                    : BudgetReservationStatus::Settled,
                'settled_estimated_cost_minor_units' => $charge,
            ]);

            return $charge;
        });
    }

    public function releaseAttemptBudget(AiRunAttempt $attempt): void
    {
        DB::transaction(function () use ($attempt) {
            /** @var AiRunAttempt|null $lockedAttempt */
            $lockedAttempt = AiRunAttempt::query()
                ->where('organization_id', $attempt->organization_id)
                ->where('id', $attempt->id)
                ->lockForUpdate()
                ->first();

            if ($lockedAttempt === null || $lockedAttempt->budget_reservation_status !== BudgetReservationStatus::Reserved) {
                return;
            }

            $reserved = (int) $lockedAttempt->reserved_cost_minor_units;
            $usageDate = $lockedAttempt->budget_usage_date->toDateString();

            $budget = DB::table('ai_organization_daily_budgets')
                ->where('organization_id', $lockedAttempt->organization_id)
                ->whereDate('usage_date', $usageDate)
                ->lockForUpdate()
                ->first();

            if ($budget) {
                $newReserved = max(0, (int) $budget->reserved_minor_units - $reserved);

                DB::table('ai_organization_daily_budgets')
                    ->where('id', $budget->id)
                    ->update([
                        'reserved_minor_units' => $newReserved,
                        'updated_at' => Carbon::now(),
                    ]);
            }

            $lockedAttempt->update([
                'budget_reservation_status' => BudgetReservationStatus::Released,
            ]);
        });
    }

    public function chargeAttemptConservatively(AiRunAttempt $attempt): void
    {
        DB::transaction(function () use ($attempt) {
            /** @var AiRunAttempt|null $lockedAttempt */
            $lockedAttempt = AiRunAttempt::query()
                ->where('organization_id', $attempt->organization_id)
                ->where('id', $attempt->id)
                ->lockForUpdate()
                ->first();

            if ($lockedAttempt === null || $lockedAttempt->budget_reservation_status !== BudgetReservationStatus::Reserved) {
                return;
            }

            $reserved = (int) $lockedAttempt->reserved_cost_minor_units;
            $usageDate = $lockedAttempt->budget_usage_date->toDateString();

            $budget = DB::table('ai_organization_daily_budgets')
                ->where('organization_id', $lockedAttempt->organization_id)
                ->whereDate('usage_date', $usageDate)
                ->lockForUpdate()
                ->first();

            if ($budget) {
                $maxDailyLimit = $this->dailyLimit((int) $lockedAttempt->organization_id);
                $newReserved = max(0, (int) $budget->reserved_minor_units - $reserved);
                $newSpent = (int) $budget->spent_minor_units + min(
                    max(0, $maxDailyLimit - (int) $budget->spent_minor_units),
                    $reserved,
                );

                DB::table('ai_organization_daily_budgets')
                    ->where('id', $budget->id)
                    ->update([
                        'spent_minor_units' => $newSpent,
                        'reserved_minor_units' => $newReserved,
                        'updated_at' => Carbon::now(),
                    ]);
            }

            $lockedAttempt->update([
                'budget_reservation_status' => BudgetReservationStatus::ConservativelyCharged,
                'settled_estimated_cost_minor_units' => $reserved,
            ]);
        });
    }

    public function settleRetrievalEmbeddingBudget(AiRun $run, int $settledMinorUnits): int
    {
        return (int) DB::transaction(function () use ($run, $settledMinorUnits): int {
            $lockedRun = $this->lockedRun($run);
            if ($lockedRun === null || $lockedRun->retrieval_embedding_budget_status !== 'reserved') {
                return 0;
            }

            $reserved = (int) $lockedRun->retrieval_embedding_reserved_cost_minor_units;
            $usageDate = $lockedRun->retrieval_embedding_usage_date?->toDateString();
            $budget = $usageDate === null ? null : DB::table('ai_organization_daily_budgets')
                ->where('organization_id', $lockedRun->organization_id)
                ->whereDate('usage_date', $usageDate)
                ->lockForUpdate()
                ->first();

            $actual = max(0, $settledMinorUnits);
            $anomaly = $actual > $reserved;
            $charge = $actual;
            if ($budget !== null) {
                $maxDailyLimit = $this->dailyLimit((int) $lockedRun->organization_id);
                $spent = (int) $budget->spent_minor_units;
                $anomaly = $anomaly || $spent + $actual > $maxDailyLimit;
                $charge = $anomaly ? max($reserved, $actual) : $actual;
                $charge = min(max(0, $maxDailyLimit - $spent), $charge);
                DB::table('ai_organization_daily_budgets')
                    ->where('id', $budget->id)
                    ->update([
                        'spent_minor_units' => $spent + $charge,
                        'reserved_minor_units' => max(0, (int) $budget->reserved_minor_units - $reserved),
                        'updated_at' => Carbon::now(),
                    ]);
            }

            $lockedRun->update([
                'retrieval_embedding_budget_status' => $anomaly ? 'conservatively_charged' : 'settled',
                'retrieval_embedding_settled_cost_minor_units' => $charge,
            ]);

            return $charge;
        });
    }

    public function releaseRetrievalEmbeddingBudget(AiRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $lockedRun = $this->lockedRun($run);
            if ($lockedRun === null || $lockedRun->retrieval_embedding_budget_status !== 'reserved') {
                return;
            }

            $this->changeRetrievalReservation($lockedRun, charge: 0);
            $lockedRun->update(['retrieval_embedding_budget_status' => 'released']);
        });
    }

    public function chargeRetrievalEmbeddingConservatively(AiRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $lockedRun = $this->lockedRun($run);
            if ($lockedRun === null || $lockedRun->retrieval_embedding_budget_status !== 'reserved') {
                return;
            }

            $reserved = (int) $lockedRun->retrieval_embedding_reserved_cost_minor_units;
            $this->changeRetrievalReservation($lockedRun, charge: $reserved);
            $lockedRun->update([
                'retrieval_embedding_budget_status' => 'conservatively_charged',
                'retrieval_embedding_settled_cost_minor_units' => $reserved,
            ]);
        });
    }

    private function lockedRun(AiRun $run): ?AiRun
    {
        return AiRun::query()
            ->where('organization_id', $run->organization_id)
            ->whereKey($run->id)
            ->lockForUpdate()
            ->first();
    }

    private function changeRetrievalReservation(AiRun $run, int $charge): void
    {
        $reserved = (int) $run->retrieval_embedding_reserved_cost_minor_units;
        $usageDate = $run->retrieval_embedding_usage_date?->toDateString();
        if ($usageDate === null) {
            return;
        }

        $budget = DB::table('ai_organization_daily_budgets')
            ->where('organization_id', $run->organization_id)
            ->whereDate('usage_date', $usageDate)
            ->lockForUpdate()
            ->first();
        if ($budget === null) {
            return;
        }

        $maxDailyLimit = $this->dailyLimit((int) $run->organization_id);
        $spent = (int) $budget->spent_minor_units;
        $actualCharge = min(max(0, $maxDailyLimit - $spent), max(0, $charge));
        DB::table('ai_organization_daily_budgets')
            ->where('id', $budget->id)
            ->update([
                'spent_minor_units' => $spent + $actualCharge,
                'reserved_minor_units' => max(0, (int) $budget->reserved_minor_units - $reserved),
                'updated_at' => Carbon::now(),
            ]);
    }

    private function dailyLimit(int $organizationId): int
    {
        return AiRuntimeLimits::effectiveDailySpendLimit(
            (int) (AiOrganizationSafetyControl::query()
                ->where('organization_id', $organizationId)
                ->value('max_daily_spend_minor_units') ?? AiRuntimeLimits::dailySpendCeiling()),
        );
    }
}
