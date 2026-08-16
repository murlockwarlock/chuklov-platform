<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Domain\Contracts\AiSafetyBudgetManagerInterface;
use App\Modules\AI\Domain\Enums\BudgetReservationStatus;
use App\Modules\AI\Domain\Exceptions\AiBudgetExceededException;
use App\Modules\AI\Domain\Models\AiOrganizationDailyBudget;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunAttempt;
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

        // Repeated settlement on same attempt (must be a NO-OP)
        $this->budgetManager->settleAttemptBudget($attempt, 50);

        $row->refresh();
        $this->assertSame(50, $row->spent_minor_units);
        $this->assertSame(0, $row->reserved_minor_units);
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

        // Repeat release (no-op)
        $this->budgetManager->releaseAttemptBudget($attempt);

        $row->refresh();
        $this->assertSame(0, $row->spent_minor_units);
        $this->assertSame(0, $row->reserved_minor_units);
    }

    public function test_conservative_charge_transfers_reserved_to_spent(): void
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
    }
}
