<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\AI\Application\Actions\CreateAndActivateModelRelease;
use App\Modules\AI\Application\Actions\DispatchAsyncAiRun;
use App\Modules\AI\Application\Actions\ReclaimExpiredAiRuns;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Domain\Contracts\AiSafetyBudgetManagerInterface;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Exceptions\AiBudgetExceededException;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiOrganizationDailyBudget;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Infrastructure\Jobs\ProcessAiRunJob;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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

        $results = Concurrency::driver('process')->run([
            static fn (): array => self::reclaimExpiredRun(1),
            static fn (): array => self::reclaimExpiredRun(1),
        ]);

        self::assertSame([0, 1], collect($results)->pluck('reclaimed')->sort()->values()->all());
        self::assertSame(1, AiRun::query()->whereKey($run->id)->where('status', 'queued')->count());
        self::assertNotSame($run->worker_lease_token, AiRun::query()->whereKey($run->id)->value('worker_lease_token'));
        self::assertSame(1, array_sum(array_map(static fn (array $result): int => $result['queued_jobs'], $results)));
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
