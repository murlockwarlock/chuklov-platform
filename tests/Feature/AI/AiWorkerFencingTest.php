<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\DispatchAsyncAiRun;
use App\Modules\AI\Application\Actions\ReclaimExpiredAiRuns;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Domain\Contracts\AiSafetyBudgetManagerInterface;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiOrganizationDailyBudget;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunAttempt;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Infrastructure\Engine\DynamicWorkflowAgent;
use App\Modules\AI\Infrastructure\Jobs\ProcessAiRunJob;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiWorkerFencingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Chuklov Clinic',
            'slug' => 'chuklov-clinic',
        ]);

        $this->user = User::factory()->forOrganization($this->organization, OrganizationRole::Administrator)->create();

        config()->set('tenancy.default_organization_id', $this->organization->id);
        app(OrganizationContext::class)->set($this->organization);

        $this->setupConfiguredModel(AiCapability::ClientCompanion);
    }

    private function setupConfiguredModel(AiCapability $capability, string $providerName = 'openai', string $modelName = 'gpt-4o-mini'): void
    {
        $credential = new OrganizationCredential([
            'provider' => $providerName,
            'credential_name' => "{$providerName} Prod",
            'revision_id' => (string) Str::uuid(),
        ]);
        $credential->organization_id = max(0, (int) $this->organization->id);
        $credential->credentials = ['api_key' => 'sk-test'];
        $credential->status = CredentialStatus::Active;
        $credential->save();

        $provider = AiProviderConfiguration::create([
            'organization_id' => $this->organization->id,
            'provider_name' => $providerName,
            'display_name' => ucfirst($providerName),
            'is_enabled' => true,
            'health_status' => ProviderHealthStatus::Healthy,
            'credential_id' => $credential->id,
        ]);

        $pricing = new AiPricingSnapshot(currency: 'USD', inputCostPerMillionMinorUnits: 15, outputCostPerMillionMinorUnits: 60);

        $model = AiModelConfiguration::create([
            'organization_id' => $this->organization->id,
            'provider_config_id' => $provider->id,
            'model_name' => $modelName,
            'display_name' => strtoupper($modelName),
            'is_enabled' => true,
            'capabilities' => [$capability->value],
            'pricing_snapshot' => $pricing->toArray(),
            'failover_priority' => 1,
        ]);

        $release = AiModelRelease::create([
            'organization_id' => $this->organization->id,
            'model_config_id' => $model->id,
            'release_number' => 1,
            'status' => 'active',
            'provider_name' => $providerName,
            'model_name' => $modelName,
            'capabilities' => [$capability->value],
            'pricing_snapshot' => $pricing->toArray(),
            'activated_at' => Carbon::now(),
        ]);
        $model->update(['active_release_id' => $release->id]);
    }

    public function test_async_dispatch_creates_queued_run_and_worker_executes_it(): void
    {
        DynamicWorkflowAgent::fake(['Async generation completed']);

        $dispatcher = app(DispatchAsyncAiRun::class);

        $request = new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'async_companion_test',
            origin: AiRunOrigin::User,
            initiatedByUserId: $this->user->id,
            inputVariables: ['query' => 'Расскажите о восстановлении'],
        );

        $run = $dispatcher->handle($this->user, $request);

        $this->assertSame(AiRunStatus::Queued, $run->status);
        $this->assertNotNull($run->worker_lease_token);

        // Process job
        $job = new ProcessAiRunJob($this->organization->id, $run->id);
        $job->handle(app(AiWorkflowEngine::class), app(AiSafetyBudgetManagerInterface::class));

        $run->refresh();
        $this->assertSame(AiRunStatus::Succeeded, $run->status);

        $payload = AiRunPayload::where('ai_run_id', $run->id)->first();
        $this->assertNotNull($payload);
        $this->assertNotNull($payload->encrypted_output_text);
    }

    public function test_stale_worker_with_mismatched_lease_token_cannot_finalize_run_or_write_payload(): void
    {
        DynamicWorkflowAgent::fake(['Stale output that should not be saved']);

        $engine = app(AiWorkflowEngine::class);

        $tokenA = (string) Str::uuid();
        $tokenB = (string) Str::uuid();

        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'fencing_test',
            'status' => AiRunStatus::Running,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
            'worker_lease_token' => $tokenB, // Reclaimed by worker B
            'worker_lease_expires_at' => Carbon::now()->addMinutes(5),
            'started_at' => Carbon::now(),
        ]);

        AiRunPayload::create([
            'organization_id' => $this->organization->id,
            'ai_run_id' => $run->id,
            'encryption_key_version' => 1,
            'encrypted_system_prompt' => null,
            'encrypted_user_prompt' => null,
        ]);

        // Worker A attempts to execute with stale token A
        $result = $engine->executeRun(
            organizationId: $this->organization->id,
            runId: $run->id,
            workerLeaseToken: $tokenA, // Stale!
        );

        $this->assertFalse($result->isSuccess());

        $run->refresh();
        // Run remains Running with worker B's token, was not finalized by stale worker A
        $this->assertSame(AiRunStatus::Running, $run->status);
        $this->assertSame($tokenB, $run->worker_lease_token);

        // Verify payload was NOT updated by worker A
        $payload = AiRunPayload::where('ai_run_id', $run->id)->first();
        $this->assertNull($payload?->encrypted_output_text);
    }

    public function test_stale_worker_on_kill_switch_cannot_overwrite_new_owner_state(): void
    {
        AiOrganizationSafetyControl::create([
            'organization_id' => $this->organization->id,
            'is_ai_globally_enabled' => false,
        ]);

        $engine = app(AiWorkflowEngine::class);

        $tokenA = (string) Str::uuid();
        $tokenB = (string) Str::uuid();

        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'kill_switch_fencing_test',
            'status' => AiRunStatus::Running,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
            'worker_lease_token' => $tokenB, // Reclaimed by Worker B
            'worker_lease_expires_at' => Carbon::now()->addMinutes(5),
            'started_at' => Carbon::now(),
        ]);

        // Stale Worker A invokes executeRun with token A
        $result = $engine->executeRun(
            organizationId: $this->organization->id,
            runId: $run->id,
            workerLeaseToken: $tokenA,
        );

        $this->assertFalse($result->isSuccess());

        $run->refresh();
        // Run must remain in Running state under token B, not corrupted to Failed by Worker A
        $this->assertSame(AiRunStatus::Running, $run->status);
        $this->assertSame($tokenB, $run->worker_lease_token);
    }

    public function test_stale_worker_on_disabled_capability_cannot_overwrite_new_owner_state(): void
    {
        AiOrganizationSafetyControl::create([
            'organization_id' => $this->organization->id,
            'is_ai_globally_enabled' => true,
            'disabled_capabilities' => [AiCapability::ClientCompanion->value],
        ]);

        $engine = app(AiWorkflowEngine::class);

        $tokenA = (string) Str::uuid();
        $tokenB = (string) Str::uuid();

        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'cap_disabled_fencing_test',
            'status' => AiRunStatus::Running,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
            'worker_lease_token' => $tokenB, // Reclaimed by Worker B
            'worker_lease_expires_at' => Carbon::now()->addMinutes(5),
            'started_at' => Carbon::now(),
        ]);

        // Stale Worker A invokes executeRun with token A
        $result = $engine->executeRun(
            organizationId: $this->organization->id,
            runId: $run->id,
            workerLeaseToken: $tokenA,
        );

        $this->assertFalse($result->isSuccess());

        $run->refresh();
        $this->assertSame(AiRunStatus::Running, $run->status);
        $this->assertSame($tokenB, $run->worker_lease_token);
    }

    public function test_stale_worker_reaching_provider_exhaustion_cannot_overwrite_new_owner_state(): void
    {
        // Provider throws exception during execution
        DynamicWorkflowAgent::fake([
            new \RuntimeException('Connection timed out'),
        ]);

        $engine = app(AiWorkflowEngine::class);

        $tokenA = (string) Str::uuid();
        $tokenB = (string) Str::uuid();

        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'exhaustion_fencing_test',
            'status' => AiRunStatus::Running,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
            'worker_lease_token' => $tokenA,
            'worker_lease_expires_at' => Carbon::now()->addMinutes(5),
            'started_at' => Carbon::now(),
        ]);

        AiRunPayload::create([
            'organization_id' => $this->organization->id,
            'ai_run_id' => $run->id,
            'encryption_key_version' => 1,
        ]);

        // While Worker A is executing, simulate that Worker B reclaims the run
        $run->update([
            'worker_lease_token' => $tokenB,
            'status' => AiRunStatus::Running,
        ]);

        // Worker A completes its failed attempts and enters final terminal write
        $result = $engine->executeRun(
            organizationId: $this->organization->id,
            runId: $run->id,
            workerLeaseToken: $tokenA, // Old token!
        );

        $this->assertFalse($result->isSuccess());

        $run->refresh();
        // Run MUST remain running under Worker B's token, NOT overwritten to Failed by stale Worker A
        $this->assertSame(AiRunStatus::Running, $run->status);
        $this->assertSame($tokenB, $run->worker_lease_token);
    }

    public function test_expired_lease_is_reclaimed_by_new_worker_with_new_token(): void
    {
        DynamicWorkflowAgent::fake(['Output from new worker C']);

        $staleToken = (string) Str::uuid();

        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'reclaim_test',
            'status' => AiRunStatus::Running,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
            'worker_lease_token' => $staleToken,
            'worker_lease_expires_at' => Carbon::now()->subMinutes(10), // Expired!
            'started_at' => Carbon::now()->subMinutes(12),
        ]);

        AiRunPayload::create([
            'organization_id' => $this->organization->id,
            'ai_run_id' => $run->id,
            'encryption_key_version' => 1,
        ]);

        // Old dangling attempt
        AiRunAttempt::create([
            'organization_id' => $this->organization->id,
            'ai_run_id' => $run->id,
            'attempt_number' => 1,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'status' => 'running',
            'pricing_snapshot' => [],
            'token_usage' => [],
            'budget_usage_date' => Carbon::now()->toDateString(),
            'started_at' => Carbon::now()->subMinutes(12),
        ]);

        // Process job claims and reclaims expired run
        $job = new ProcessAiRunJob($this->organization->id, $run->id);
        $job->handle(app(AiWorkflowEngine::class), app(AiSafetyBudgetManagerInterface::class));

        $run->refresh();
        $this->assertSame(AiRunStatus::Succeeded, $run->status);
        $this->assertNotSame($staleToken, $run->worker_lease_token);

        // Verify dangling attempt was reconciled
        $oldAttempt = AiRunAttempt::where('ai_run_id', $run->id)->where('attempt_number', 1)->first();
        $this->assertSame('failed', $oldAttempt?->status);
    }

    public function test_scheduled_reclaimer_claims_expired_work_once_and_reconciles_old_reservation(): void
    {
        Queue::fake();
        $oldToken = (string) Str::uuid();
        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'scheduled_reclaim_test',
            'status' => AiRunStatus::Running,
            'worker_lease_token' => $oldToken,
            'worker_lease_expires_at' => Carbon::now()->subMinutes(10),
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);
        $today = Carbon::now()->toDateString();
        AiOrganizationDailyBudget::create([
            'organization_id' => $this->organization->id,
            'usage_date' => $today,
            'spent_minor_units' => 10,
            'reserved_minor_units' => 100,
        ]);
        $attempt = AiRunAttempt::create([
            'organization_id' => $this->organization->id,
            'ai_run_id' => $run->id,
            'attempt_number' => 1,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'worker_lease_token' => $oldToken,
            'status' => 'running',
            'reserved_cost_minor_units' => 100,
            'budget_usage_date' => $today,
            'budget_reservation_status' => 'reserved',
            'pricing_snapshot' => [],
            'token_usage' => [],
        ]);

        $firstPass = app(ReclaimExpiredAiRuns::class)->handle(10);

        $this->assertSame(['reclaimed' => 1, 'dispatched' => 1], $firstPass);
        Queue::assertPushed(ProcessAiRunJob::class, 1);
        $run->refresh();
        $this->assertSame(AiRunStatus::Queued, $run->status);
        $this->assertNotSame($oldToken, $run->worker_lease_token);
        $attempt->refresh();
        $this->assertSame('failed', $attempt->status);
        $this->assertSame('conservatively_charged', $attempt->budget_reservation_status->value);

        $budget = AiOrganizationDailyBudget::query()
            ->where('organization_id', $this->organization->id)
            ->whereDate('usage_date', $today)
            ->first();
        $this->assertNotNull($budget);
        $this->assertSame(110, $budget->spent_minor_units);
        $this->assertSame(0, $budget->reserved_minor_units);

        $secondPass = app(ReclaimExpiredAiRuns::class)->handle(10);
        $this->assertSame(['reclaimed' => 0, 'dispatched' => 0], $secondPass);
        Queue::assertPushed(ProcessAiRunJob::class, 1);
    }

    public function test_scheduled_reclaimer_never_redispatches_terminal_runs(): void
    {
        Queue::fake();
        AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'terminal_reclaim_test',
            'status' => AiRunStatus::Succeeded,
            'worker_lease_token' => (string) Str::uuid(),
            'worker_lease_expires_at' => Carbon::now()->subMinutes(10),
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);

        $result = app(ReclaimExpiredAiRuns::class)->handle();

        $this->assertSame(['reclaimed' => 0, 'dispatched' => 0], $result);
        Queue::assertNothingPushed();
    }
}
