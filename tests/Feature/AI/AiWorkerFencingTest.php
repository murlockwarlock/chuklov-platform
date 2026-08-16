<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\DispatchAsyncAiRun;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunAttempt;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\AI\Infrastructure\Engine\DynamicWorkflowAgent;
use App\Modules\AI\Infrastructure\Jobs\ProcessAiRunJob;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $job->handle(app(AiWorkflowEngine::class));

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

        // Create active run with token A
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
        $job->handle(app(AiWorkflowEngine::class));

        $run->refresh();
        $this->assertSame(AiRunStatus::Succeeded, $run->status);
        $this->assertNotSame($staleToken, $run->worker_lease_token);

        // Verify dangling attempt was reconciled
        $oldAttempt = AiRunAttempt::where('ai_run_id', $run->id)->where('attempt_number', 1)->first();
        $this->assertSame('failed', $oldAttempt?->status);
    }
}
