<?php

namespace App\Modules\AI\Infrastructure\Safety;

use App\Modules\AI\Domain\Contracts\AiSafetyBudgetManagerInterface;
use App\Modules\AI\Domain\Enums\BudgetReservationStatus;
use App\Modules\AI\Domain\Exceptions\AiBudgetExceededException;
use App\Modules\AI\Domain\Exceptions\AiKillSwitchException;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Models\AiRunAttempt;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AtomicAiSafetyBudgetManager implements AiSafetyBudgetManagerInterface
{
    public function reserveBudget(int $organizationId, int $requestedMinorUnits): void
    {
        $safetyControls = AiOrganizationSafetyControl::query()
            ->where('organization_id', $organizationId)
            ->first();

        if ($safetyControls !== null && ! $safetyControls->is_ai_globally_enabled) {
            throw new AiKillSwitchException('AI is globally disabled for this organization.');
        }

        $maxDailyLimit = $safetyControls !== null
            ? $safetyControls->max_daily_spend_minor_units
            : 5000;

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
                $newSpent = (int) $budget->spent_minor_units + $settledMinorUnits;

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
                $newSpent = (int) $budget->spent_minor_units + $reservedMinorUnits;

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

    public function settleAttemptBudget(AiRunAttempt $attempt, int $settledMinorUnits): void
    {
        DB::transaction(function () use ($attempt, $settledMinorUnits) {
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
                $newSpent = (int) $budget->spent_minor_units + $settledMinorUnits;

                DB::table('ai_organization_daily_budgets')
                    ->where('id', $budget->id)
                    ->update([
                        'spent_minor_units' => $newSpent,
                        'reserved_minor_units' => $newReserved,
                        'updated_at' => Carbon::now(),
                    ]);
            }

            $lockedAttempt->update([
                'budget_reservation_status' => BudgetReservationStatus::Settled,
                'settled_estimated_cost_minor_units' => $settledMinorUnits,
            ]);
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
                $newReserved = max(0, (int) $budget->reserved_minor_units - $reserved);
                $newSpent = (int) $budget->spent_minor_units + $reserved;

                DB::table('ai_organization_daily_budgets')
                    ->where('id', $budget->id)
                    ->update([
                        'spent_minor_units' => $newSpent,
                        'reserved_minor_units' => $newReserved,
                        'updated_at' => Carbon::now(),
                    ]);
            }

            $lockedAttempt->update([
                'budget_reservation_status' => BudgetReservationStatus::Settled,
                'settled_estimated_cost_minor_units' => $reserved,
            ]);
        });
    }
}
