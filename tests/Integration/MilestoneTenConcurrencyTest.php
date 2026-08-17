<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\AI\Application\Actions\CreateAndActivateModelRelease;
use App\Modules\AI\Application\Actions\DispatchAsyncAiRun;
use App\Modules\AI\Application\Actions\ReclaimExpiredAiRuns;
use App\Modules\AI\Application\Actions\ReconcileExpiredAiRun;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Domain\Contracts\AiSafetyBudgetManagerInterface;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\BudgetReservationStatus;
use App\Modules\AI\Domain\Exceptions\AiBudgetExceededException;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiOrganizationDailyBudget;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunAttempt;
use App\Modules\AI\Domain\Models\AiRunToolCall;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Domain\ValueObjects\AiRunExecutionContext;
use App\Modules\AI\Infrastructure\Jobs\ProcessAiRunJob;
use App\Modules\AI\Infrastructure\Tools\SearchKnowledgeBaseSdkTool;
use App\Modules\AI\Infrastructure\Tools\SearchKnowledgeBaseTool;
use App\Modules\Knowledge\Application\Data\RetrievalQuery;
use App\Modules\Knowledge\Domain\Contracts\KnowledgeRetriever;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

final class MilestoneTenConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_concurrent_budget_reservations_respect_maximum_limit(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Budget reservation concurrency requires PostgreSQL row locks.');
        }

        $organization = Organization::factory()->create();
        AiOrganizationSafetyControl::query()->create([
            'organization_id' => $organization->id,
            'max_daily_spend_minor_units' => 100,
        ]);

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::reserve($organization->id, 80),
            static fn (): string => self::reserve($organization->id, 80),
        ]);

        // One must succeed ('reserved') and the other must fail ('exceeded')
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'reserved')));
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'exceeded')));

        $budget = AiOrganizationDailyBudget::query()
            ->where('organization_id', $organization->id)
            ->whereDate('usage_date', Carbon::now()->toDateString())
            ->first();

        self::assertNotNull($budget);
        self::assertLessThanOrEqual(100, $budget->spent_minor_units + $budget->reserved_minor_units);
        self::assertSame(80, (int) $budget->reserved_minor_units);
    }

    public function test_concurrent_async_idempotency_creates_one_run_and_one_logical_queue_dispatch(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Async idempotency concurrency requires PostgreSQL unique-violation semantics.');
        }

        $organization = Organization::factory()->create();
        $user = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $key = 'pg-concurrent-'.Str::uuid();
        $prompt = AiPrompt::query()->create([
            'organization_id' => $organization->id,
            'key' => 'concurrent_async_prompt',
            'name' => 'Concurrent async prompt',
            'capability' => AiCapability::ClientCompanion,
        ]);
        $version = AiPromptVersion::query()->create([
            'organization_id' => $organization->id,
            'prompt_id' => $prompt->id,
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'Use the versioned concurrent test instructions.',
            'user_prompt_template' => '{{query}}',
            'context_policy' => [],
            'allowed_tools' => [],
            'activated_at' => Carbon::now(),
        ]);
        $prompt->update(['active_version_id' => $version->id]);

        $results = Concurrency::driver('process')->run([
            static fn (): array => self::dispatchDuplicateAsyncRun($organization->id, $user->id, $key),
            static fn (): array => self::dispatchDuplicateAsyncRun($organization->id, $user->id, $key),
        ]);

        self::assertCount(2, $results);
        self::assertSame(1, AiRun::query()
            ->where('organization_id', $organization->id)
            ->where('idempotency_key', $key)
            ->count());
        self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['queued_jobs'] === 1));
        self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['queued_jobs'] === 0));
        self::assertSame($results[0]['run_id'], $results[1]['run_id']);
    }

    public function test_concurrent_release_activation_serializes_release_numbers_and_active_state(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Release activation concurrency requires PostgreSQL row locks.');
        }

        $organization = Organization::factory()->create();
        $user = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $provider = AiProviderConfiguration::create([
            'organization_id' => $organization->id,
            'provider_name' => 'openai',
            'display_name' => 'OpenAI',
            'is_enabled' => true,
        ]);
        $pricing = new AiPricingSnapshot(currency: 'USD', inputCostPerMillionMinorUnits: 15, outputCostPerMillionMinorUnits: 60);
        $model = AiModelConfiguration::create([
            'organization_id' => $organization->id,
            'provider_config_id' => $provider->id,
            'model_name' => 'gpt-4o-mini',
            'display_name' => 'GPT-4o Mini',
            'is_enabled' => false,
            'lifecycle_status' => 'preview',
            'capabilities' => [AiCapability::ClientCompanion->value],
            'pricing_snapshot' => $pricing->toArray(),
            'failover_priority' => 1,
        ]);

        $results = Concurrency::driver('process')->run([
            static fn (): array => self::activateRelease($organization->id, $user->id, $model->id),
            static fn (): array => self::activateRelease($organization->id, $user->id, $model->id),
        ]);

        self::assertSame([], array_filter($results, static fn (array $result): bool => isset($result['error'])));
        self::assertSame([1, 2], collect($results)->pluck('release_number')->sort()->values()->all());
        self::assertSame(1, AiModelRelease::query()
            ->where('organization_id', $organization->id)
            ->where('model_config_id', $model->id)
            ->where('status', 'active')
            ->count());
    }

    public function test_concurrent_scheduled_reclaim_claims_one_expired_run_and_dispatches_one_job(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Scheduled reclaim locking requires PostgreSQL row locks.');
        }

        $organization = Organization::factory()->create();
        $run = AiRun::create([
            'organization_id' => $organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'concurrent_reclaim',
            'status' => AiRunStatus::Running,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
            'worker_lease_token' => (string) Str::uuid(),
            'worker_lease_expires_at' => Carbon::now()->subMinute(),
        ]);
        $today = Carbon::now()->toDateString();
        AiOrganizationDailyBudget::create([
            'organization_id' => $organization->id,
            'usage_date' => $today,
            'spent_minor_units' => 0,
            'reserved_minor_units' => 80,
        ]);
        $attempt = AiRunAttempt::create([
            'organization_id' => $organization->id,
            'ai_run_id' => $run->id,
            'attempt_number' => 1,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'worker_lease_token' => $run->worker_lease_token,
            'status' => 'running',
            'reserved_cost_minor_units' => 80,
            'budget_usage_date' => $today,
            'budget_reservation_status' => BudgetReservationStatus::Reserved,
            'pricing_snapshot' => [],
            'token_usage' => [],
        ]);

        $results = Concurrency::driver('process')->run([
            static fn (): array => self::reclaimExpiredRun(1),
            static fn (): array => self::reclaimExpiredRun(1),
        ]);

        self::assertSame([0, 1], collect($results)->pluck('reclaimed')->sort()->values()->all());
        self::assertSame(1, AiRun::query()->whereKey($run->id)->where('status', 'queued')->count());
        self::assertNotSame($run->worker_lease_token, AiRun::query()->whereKey($run->id)->value('worker_lease_token'));
        self::assertSame(1, array_sum(array_map(static fn (array $result): int => $result['queued_jobs'], $results)));
        self::assertSame('failed', $attempt->refresh()->status);
        self::assertSame(BudgetReservationStatus::ConservativelyCharged, $attempt->budget_reservation_status);
        $budget = AiOrganizationDailyBudget::query()
            ->where('organization_id', $organization->id)
            ->whereDate('usage_date', $today)
            ->firstOrFail();
        self::assertSame(80, $budget->spent_minor_units);
        self::assertSame(0, $budget->reserved_minor_units);
    }

    public function test_lease_transfer_during_simulated_blocked_provider_fences_stale_attempt_then_reconciles_once(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Lease-transfer fencing requires PostgreSQL process isolation.');
        }

        $organization = Organization::factory()->create();
        $tokenA = (string) Str::uuid();
        $tokenB = (string) Str::uuid();
        $run = AiRun::create([
            'organization_id' => $organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'pg_lease_transfer_fence',
            'status' => AiRunStatus::Running,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
            'worker_lease_token' => $tokenA,
            'worker_lease_expires_at' => Carbon::now()->addMinutes(5),
            'execution_deadline_at' => Carbon::now()->addMinutes(10),
        ]);
        $today = Carbon::now()->toDateString();
        AiOrganizationDailyBudget::create([
            'organization_id' => $organization->id,
            'usage_date' => $today,
            'spent_minor_units' => 0,
            'reserved_minor_units' => 40,
        ]);
        $attempt = AiRunAttempt::create([
            'organization_id' => $organization->id,
            'ai_run_id' => $run->id,
            'attempt_number' => 1,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'worker_lease_token' => $tokenA,
            'status' => 'running',
            'reserved_cost_minor_units' => 40,
            'budget_usage_date' => $today,
            'budget_reservation_status' => BudgetReservationStatus::Reserved,
            'pricing_snapshot' => [],
            'token_usage' => [],
        ]);

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::simulateStaleProviderAttemptCommit($run->id, $organization->id, $tokenA),
            static fn (): string => self::transferLeaseDuringProviderExecution($run->id, $organization->id, $tokenB),
        ]);

        self::assertContains('fenced', $results);
        self::assertContains('transferred', $results);
        self::assertSame('running', $attempt->refresh()->status);
        self::assertSame(BudgetReservationStatus::Reserved, $attempt->budget_reservation_status);

        Queue::fake();
        $reclaim = app(ReclaimExpiredAiRuns::class)->handle();
        self::assertSame(['reclaimed' => 1, 'dispatched' => 1], $reclaim);
        self::assertSame('failed', $attempt->refresh()->status);
        self::assertSame(BudgetReservationStatus::ConservativelyCharged, $attempt->budget_reservation_status);
        $budget = AiOrganizationDailyBudget::query()
            ->where('organization_id', $organization->id)
            ->whereDate('usage_date', $today)
            ->firstOrFail();
        self::assertSame(40, $budget->spent_minor_units);
        self::assertSame(0, $budget->reserved_minor_units);

        app(ReconcileExpiredAiRun::class)->handle($run->refresh(), 'Repeated PostgreSQL reconciliation.');
        self::assertSame(40, $budget->refresh()->spent_minor_units);
    }

    public function test_concurrent_tool_claims_use_durable_fenced_call_indexes(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Tool provenance locking requires PostgreSQL process isolation.');
        }

        $organization = Organization::factory()->create();
        $token = (string) Str::uuid();
        $run = AiRun::create([
            'organization_id' => $organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'pg_tool_claim_index',
            'status' => AiRunStatus::Running,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
            'worker_lease_token' => $token,
            'worker_lease_expires_at' => Carbon::now()->addMinutes(5),
            'execution_deadline_at' => Carbon::now()->addMinutes(10),
        ]);

        $results = Concurrency::driver('process')->run([
            static fn (): int => self::executeConcurrentEmptyKnowledgeTool($organization->id, $run->id, $token, 'one'),
            static fn (): int => self::executeConcurrentEmptyKnowledgeTool($organization->id, $run->id, $token, 'two'),
        ]);

        self::assertSame([1, 2], collect($results)->sort()->values()->all());
        self::assertSame([1, 2], AiRunToolCall::query()
            ->where('organization_id', $organization->id)
            ->where('ai_run_id', $run->id)
            ->orderBy('call_index')
            ->pluck('call_index')
            ->all());
    }

    /** @return array{run_id: int, queued_jobs: int} */
    private static function dispatchDuplicateAsyncRun(int $organizationId, int $userId, string $idempotencyKey): array
    {
        Queue::fake();
        $organization = Organization::query()->findOrFail($organizationId);
        config()->set('tenancy.default_organization_id', $organizationId);
        app(OrganizationContext::class)->set($organization);
        $user = User::query()->findOrFail($userId);
        $run = app(DispatchAsyncAiRun::class)->handle($user, new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'concurrent_async_idempotency',
            idempotencyKey: $idempotencyKey,
        ));

        $queuedJobs = Queue::pushedJobs()[ProcessAiRunJob::class] ?? [];

        return ['run_id' => $run->id, 'queued_jobs' => count($queuedJobs)];
    }

    /** @return array{release_number: int}|array{error: string} */
    private static function activateRelease(int $organizationId, int $userId, int $modelConfigurationId): array
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            config()->set('tenancy.default_organization_id', $organizationId);
            app(OrganizationContext::class)->set($organization);
            $release = app(CreateAndActivateModelRelease::class)->handle(
                User::query()->findOrFail($userId),
                AiModelConfiguration::query()->findOrFail($modelConfigurationId),
                [],
            );

            return ['release_number' => $release->release_number];
        } catch (\Throwable $exception) {
            return ['error' => $exception::class];
        }
    }

    /** @return array{reclaimed: int, queued_jobs: int} */
    private static function reclaimExpiredRun(int $batchSize): array
    {
        Queue::fake();
        $result = app(ReclaimExpiredAiRuns::class)->handle($batchSize);
        $queuedJobs = Queue::pushedJobs()[ProcessAiRunJob::class] ?? [];

        return [
            'reclaimed' => $result['reclaimed'],
            'queued_jobs' => count($queuedJobs),
        ];
    }

    private static function simulateStaleProviderAttemptCommit(int $runId, int $organizationId, string $workerLeaseToken): string
    {
        usleep(250000);

        return DB::transaction(function () use ($runId, $organizationId, $workerLeaseToken): string {
            $run = AiRun::query()
                ->where('organization_id', $organizationId)
                ->whereKey($runId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($run->worker_lease_token !== $workerLeaseToken) {
                return 'fenced';
            }

            AiRunAttempt::query()
                ->where('organization_id', $organizationId)
                ->where('ai_run_id', $runId)
                ->where('worker_lease_token', $workerLeaseToken)
                ->update(['status' => 'succeeded']);

            return 'committed';
        });
    }

    private static function transferLeaseDuringProviderExecution(int $runId, int $organizationId, string $newWorkerLeaseToken): string
    {
        AiRun::query()
            ->where('organization_id', $organizationId)
            ->whereKey($runId)
            ->update([
                'worker_lease_token' => $newWorkerLeaseToken,
                'worker_lease_expires_at' => Carbon::now()->subSecond(),
            ]);

        return 'transferred';
    }

    private static function executeConcurrentEmptyKnowledgeTool(int $organizationId, int $runId, string $workerLeaseToken, string $query): int
    {
        $retriever = new class implements KnowledgeRetriever
        {
            public function retrieve(User $actor, RetrievalQuery $query): array
            {
                return [];
            }

            public function retrieveForOrganization(int|string $organizationId, RetrievalQuery $query): array
            {
                return [];
            }
        };
        $tool = new SearchKnowledgeBaseSdkTool(
            executionContext: new AiRunExecutionContext($organizationId, $runId, $workerLeaseToken),
            domainTool: new SearchKnowledgeBaseTool(knowledgeRetriever: $retriever),
            maxToolCalls: 2,
        );

        $tool->handle(new Request(['query' => $query]));

        return (int) AiRunToolCall::query()
            ->where('organization_id', $organizationId)
            ->where('ai_run_id', $runId)
            ->where('input_digest', hash('sha256', json_encode(['query' => $query])))
            ->value('call_index');
    }

    private static function reserve(int $organizationId, int $amount): string
    {
        try {
            app(AiSafetyBudgetManagerInterface::class)->reserveBudget($organizationId, $amount);

            return 'reserved';
        } catch (AiBudgetExceededException) {
            return 'exceeded';
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception);
        }
    }
}
