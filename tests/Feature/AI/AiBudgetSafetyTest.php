<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Application\Data\AiRunResult;
use App\Modules\AI\Domain\Contracts\AiSafetyBudgetManagerInterface;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\BudgetReservationStatus;
use App\Modules\AI\Domain\Exceptions\AiBudgetExceededException;
use App\Modules\AI\Domain\Models\AiOrganizationDailyBudget;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunAttempt;
use App\Modules\AI\Infrastructure\Jobs\ProcessAiRunJob;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiBudgetSafetyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private AiSafetyBudgetManagerInterface $budgetManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Chuklov Clinic',
            'slug' => 'chuklov-clinic',
        ]);

        $this->budgetManager = app(AiSafetyBudgetManagerInterface::class);
    }

    public function test_first_daily_reservation_creates_row_and_increments_reserved(): void
    {
        AiOrganizationSafetyControl::create([
            'organization_id' => $this->organization->id,
            'max_daily_spend_minor_units' => 1000,
        ]);

        $today = Carbon::now()->toDateString();

        $this->budgetManager->reserveBudget($this->organization->id, 250);

        $row = AiOrganizationDailyBudget::where('organization_id', $this->organization->id)
            ->whereDate('usage_date', $today)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(0, $row->spent_minor_units);
        $this->assertSame(250, $row->reserved_minor_units);
    }

    public function test_reservation_fails_closed_when_budget_limit_exceeded(): void
    {
        AiOrganizationSafetyControl::create([
            'organization_id' => $this->organization->id,
            'max_daily_spend_minor_units' => 100,
        ]);

        $today = Carbon::now()->toDateString();
        $this->budgetManager->reserveBudget($this->organization->id, 80);

        $this->expectException(AiBudgetExceededException::class);
        // 80 + 30 = 110 > 100 limit
        $this->budgetManager->reserveBudget($this->organization->id, 30);
    }

    public function test_attempt_settlement_is_idempotent_and_does_not_double_count(): void
    {
        AiOrganizationSafetyControl::create([
            'organization_id' => $this->organization->id,
            'max_daily_spend_minor_units' => 1000,
        ]);

        $today = Carbon::now()->toDateString();
        $this->budgetManager->reserveBudget($this->organization->id, 200);

        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => 'client_companion',
            'workflow_key' => 'b_test',
            'status' => 'running',
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);

        $attempt = AiRunAttempt::create([
            'organization_id' => $this->organization->id,
            'ai_run_id' => $run->id,
            'attempt_number' => 1,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'status' => 'running',
            'reserved_cost_minor_units' => 200,
            'budget_usage_date' => $today,
            'budget_reservation_status' => BudgetReservationStatus::Reserved,
            'pricing_snapshot' => [],
            'token_usage' => [],
        ]);

        // First settlement: reserved 200 -> settled 50 (spent +50, reserved -200)
        $this->budgetManager->settleAttemptBudget($attempt, 50);

        $row = AiOrganizationDailyBudget::where('organization_id', $this->organization->id)
            ->whereDate('usage_date', $today)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(50, $row->spent_minor_units);
        $this->assertSame(0, $row->reserved_minor_units);

        $attempt->refresh();
        $this->assertSame(BudgetReservationStatus::Settled, $attempt->budget_reservation_status);

        // Repeated settlement on same attempt (must be a NO-OP)
        $this->budgetManager->settleAttemptBudget($attempt, 50);

        $row->refresh();
        $this->assertSame(50, $row->spent_minor_units);
        $this->assertSame(0, $row->reserved_minor_units);
    }

    public function test_provider_usage_anomaly_is_conservatively_accounted_without_exceeding_daily_cap(): void
    {
        AiOrganizationSafetyControl::create([
            'organization_id' => $this->organization->id,
            'max_daily_spend_minor_units' => 100,
        ]);
        $today = Carbon::now()->toDateString();
        $this->budgetManager->reserveBudget($this->organization->id, 10);

        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => 'client_companion',
            'workflow_key' => 'settlement_anomaly_test',
            'status' => 'running',
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);
        $attempt = AiRunAttempt::create([
            'organization_id' => $this->organization->id,
            'ai_run_id' => $run->id,
            'attempt_number' => 1,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'status' => 'running',
            'reserved_cost_minor_units' => 10,
            'budget_usage_date' => $today,
            'budget_reservation_status' => BudgetReservationStatus::Reserved,
            'pricing_snapshot' => [],
            'token_usage' => [],
        ]);

        $accounted = $this->budgetManager->settleAttemptBudget($attempt, 200);

        $budget = AiOrganizationDailyBudget::query()
            ->where('organization_id', $this->organization->id)
            ->whereDate('usage_date', $today)
            ->firstOrFail();
        $attempt->refresh();

        $this->assertSame(100, $accounted);
        $this->assertSame(100, $budget->spent_minor_units);
        $this->assertSame(0, $budget->reserved_minor_units);
        $this->assertSame(BudgetReservationStatus::ConservativelyCharged, $attempt->budget_reservation_status);
    }

    public function test_attempt_release_is_idempotent(): void
    {
        $today = Carbon::now()->toDateString();
        $this->budgetManager->reserveBudget($this->organization->id, 300);

        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => 'client_companion',
            'workflow_key' => 'rel_test',
            'status' => 'running',
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);

        $attempt = AiRunAttempt::create([
            'organization_id' => $this->organization->id,
            'ai_run_id' => $run->id,
            'attempt_number' => 1,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'status' => 'running',
            'reserved_cost_minor_units' => 300,
            'budget_usage_date' => $today,
            'budget_reservation_status' => BudgetReservationStatus::Reserved,
            'pricing_snapshot' => [],
            'token_usage' => [],
        ]);

        // First release: reserved 300 -> 0, spent 0
        $this->budgetManager->releaseAttemptBudget($attempt);

        $row = AiOrganizationDailyBudget::where('organization_id', $this->organization->id)
            ->whereDate('usage_date', $today)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(0, $row->spent_minor_units);
        $this->assertSame(0, $row->reserved_minor_units);

        $attempt->refresh();
        $this->assertSame(BudgetReservationStatus::Released, $attempt->budget_reservation_status);

        // Repeat release (no-op)
        $this->budgetManager->releaseAttemptBudget($attempt);

        $row->refresh();
        $this->assertSame(0, $row->spent_minor_units);
        $this->assertSame(0, $row->reserved_minor_units);
    }

    public function test_conservative_charge_transfers_reserved_to_spent_and_sets_status(): void
    {
        $today = Carbon::now()->toDateString();
        $this->budgetManager->reserveBudget($this->organization->id, 400);

        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => 'client_companion',
            'workflow_key' => 'cons_test',
            'status' => 'running',
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);

        $attempt = AiRunAttempt::create([
            'organization_id' => $this->organization->id,
            'ai_run_id' => $run->id,
            'attempt_number' => 1,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'status' => 'running',
            'reserved_cost_minor_units' => 400,
            'budget_usage_date' => $today,
            'budget_reservation_status' => BudgetReservationStatus::Reserved,
            'pricing_snapshot' => [],
            'token_usage' => [],
        ]);

        $this->budgetManager->chargeAttemptConservatively($attempt);

        $row = AiOrganizationDailyBudget::where('organization_id', $this->organization->id)
            ->whereDate('usage_date', $today)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(400, $row->spent_minor_units);
        $this->assertSame(0, $row->reserved_minor_units);

        $attempt->refresh();
        $this->assertSame(BudgetReservationStatus::ConservativelyCharged, $attempt->budget_reservation_status);
    }

    public function test_reclaim_reconciles_stale_attempt_reserved_budget_without_double_charging(): void
    {
        $today = Carbon::now()->toDateString();

        // 1. Create daily budget with 500 reserved
        $budget = AiOrganizationDailyBudget::create([
            'organization_id' => $this->organization->id,
            'usage_date' => $today,
            'spent_minor_units' => 100,
            'reserved_minor_units' => 500,
        ]);

        // 2. Create expired running run with stale attempt
        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => 'client_companion',
            'workflow_key' => 'reclaim_budget_test',
            'status' => 'running',
            'worker_lease_token' => 'old-lease-token',
            'worker_lease_expires_at' => Carbon::now()->subMinutes(10), // Expired!
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);

        $attempt = AiRunAttempt::create([
            'organization_id' => $this->organization->id,
            'ai_run_id' => $run->id,
            'attempt_number' => 1,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'status' => 'running',
            'reserved_cost_minor_units' => 500,
            'budget_usage_date' => $today,
            'budget_reservation_status' => BudgetReservationStatus::Reserved,
            'pricing_snapshot' => [],
            'token_usage' => [],
        ]);

        // 3. ProcessAiRunJob reclaims expired run
        $job = new ProcessAiRunJob($this->organization->id, $run->id);
        $fakeEngine = new class implements AiWorkflowEngine
        {
            public function run(int $organizationId, AiRunRequest $request): AiRunResult
            {
                return new AiRunResult(runId: 0, status: AiRunStatus::Succeeded);
            }

            public function executeRun(int $organizationId, int $runId, string $workerLeaseToken): AiRunResult
            {
                return new AiRunResult(runId: $runId, status: AiRunStatus::Succeeded);
            }
        };

        $job->handle($fakeEngine, $this->budgetManager);

        // 4. Assert budget row was reconciled conservatively
        $budget->refresh();
        $this->assertSame(600, $budget->spent_minor_units); // 100 + 500
        $this->assertSame(0, $budget->reserved_minor_units);

        $attempt->refresh();
        $this->assertSame(BudgetReservationStatus::ConservativelyCharged, $attempt->budget_reservation_status);
        $this->assertSame('failed', $attempt->status);
        $this->assertSame('Lease expired and reclaimed by new worker.', $attempt->retry_or_failover_reason);

        // 5. Repeated handle (must not double-charge)
        $this->budgetManager->chargeAttemptConservatively($attempt);
        $budget->refresh();
        $this->assertSame(600, $budget->spent_minor_units);
    }
}
