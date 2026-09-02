<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\AI\Application\Actions\ConnectAiProvider;
use App\Modules\AI\Application\Actions\CreateAndActivateModelRelease;
use App\Modules\AI\Application\Actions\CreatePromptDraft;
use App\Modules\AI\Application\Actions\DispatchAsyncAiRun;
use App\Modules\AI\Application\Actions\ImportPromptBundle;
use App\Modules\AI\Application\Actions\ReclaimExpiredAiRuns;
use App\Modules\AI\Application\Actions\ReconcileExpiredAiRun;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Application\Data\ContextAssemblyResult;
use App\Modules\AI\Application\Data\PromptBundle;
use App\Modules\AI\Domain\Contracts\AiContextAssemblerInterface;
use App\Modules\AI\Domain\Contracts\AiSafetyBudgetManagerInterface;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\BudgetReservationStatus;
use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Exceptions\AiBudgetExceededException;
use App\Modules\AI\Domain\Models\AiEvalSuite;
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
use App\Modules\AI\Domain\ValueObjects\AiContextPolicy;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Domain\ValueObjects\AiRunExecutionContext;
use App\Modules\AI\Infrastructure\Jobs\ProcessAiRunJob;
use App\Modules\AI\Infrastructure\Tools\SearchKnowledgeBaseSdkTool;
use App\Modules\AI\Infrastructure\Tools\SearchKnowledgeBaseTool;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Knowledge\Application\Data\RetrievalQuery;
use App\Modules\Knowledge\Domain\Contracts\EmbeddingGenerator;
use App\Modules\Knowledge\Domain\Contracts\KnowledgeRetriever;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingConfiguration;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingExecutionSnapshot;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\InvokedProcess;
use Illuminate\Process\Pool;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Laravel\SerializableClosure\SerializableClosure;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

final class CountingInitialRagEmbeddingGenerator implements EmbeddingGenerator
{
    public function __construct(private readonly int $organizationId) {}

    public function generate(array $inputs, EmbeddingConfiguration $configuration): array
    {
        DB::table('audit_events')->insert([
            'organization_id' => $this->organizationId,
            'action' => 'test.initial_rag_embedding',
            'target_type' => null,
            'target_id' => null,
            'metadata' => json_encode(['kind' => 'initial_rag_embedding'], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        return array_map(
            static fn (): array => array_fill(0, $configuration->dimensions, 0.0),
            $inputs,
        );
    }
}

final class CountingInitialRagRetriever implements KnowledgeRetriever
{
    public function __construct(private readonly EmbeddingGenerator $embeddings) {}

    public function retrieve(User $actor, RetrievalQuery $query): array
    {
        return $this->retrieveForOrganization((int) $actor->organization_id, $query);
    }

    public function retrieveForOrganization(int|string $organizationId, RetrievalQuery $query): array
    {
        $this->embeddings->generate([$query->text], EmbeddingConfiguration::active());

        return [];
    }
}

final class CountingInitialRagContextAssembler implements AiContextAssemblerInterface
{
    public function __construct(
        private readonly AiContextAssemblerInterface $delegate,
        private readonly int $organizationId,
    ) {}

    public function assemble(
        int $organizationId,
        AiContextPolicy $policy,
        array $inputVariables,
        array $inputReferences,
        ?User $actor = null,
        ?CarbonInterface $executionDeadlineAt = null,
        ?EmbeddingExecutionSnapshot $embeddingSnapshot = null,
        ?AiCapability $capability = null,
    ): ContextAssemblyResult {
        DB::table('audit_events')->insert([
            'organization_id' => $this->organizationId,
            'action' => 'test.initial_rag_context_preparation',
            'target_type' => null,
            'target_id' => null,
            'metadata' => json_encode(['kind' => 'initial_rag_context_preparation'], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        return $this->delegate->assemble(
            organizationId: $organizationId,
            policy: $policy,
            inputVariables: $inputVariables,
            inputReferences: $inputReferences,
            actor: $actor,
            executionDeadlineAt: $executionDeadlineAt,
            embeddingSnapshot: $embeddingSnapshot,
            capability: $capability,
        );
    }
}

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

    public function test_concurrent_async_idempotency_claims_before_initial_rag_and_dispatches_once(): void
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
            'context_policy' => ['include_rag' => true],
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

        $run = AiRun::query()->where('organization_id', $organization->id)->where('idempotency_key', $key)->sole();
        self::assertSame(AiRunStatus::Queued, $run->status);
        self::assertNotNull($run->payload()->first());
        self::assertSame(1, DB::table('audit_events')
            ->where('organization_id', $organization->id)
            ->where('action', 'test.initial_rag_embedding')
            ->count());
        self::assertSame(1, DB::table('audit_events')
            ->where('organization_id', $organization->id)
            ->where('action', 'test.initial_rag_context_preparation')
            ->count());
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

    public function test_concurrent_prompt_draft_and_import_versions_are_serialized(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Prompt version concurrency requires PostgreSQL row locks.');
        }

        $organization = Organization::factory()->create();
        $user = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $prompt = AiPrompt::create([
            'organization_id' => $organization->id,
            'key' => 'concurrent_draft_prompt',
            'name' => 'Concurrent draft prompt',
            'capability' => AiCapability::ClientCompanion,
        ]);

        $draftResults = Concurrency::driver('process')->run([
            static fn (): array => self::createPromptDraftInProcess($organization->id, $user->id, $prompt->id),
            static fn (): array => self::createPromptDraftInProcess($organization->id, $user->id, $prompt->id),
        ]);

        self::assertSame([], array_filter($draftResults, static fn (array $result): bool => isset($result['error'])));
        self::assertSame([1, 2], collect($draftResults)->pluck('version')->sort()->values()->all());

        $bundle = [
            'prompt_key' => 'concurrent_import_prompt',
            'name' => 'Concurrent import prompt',
            'description' => null,
            'capability' => AiCapability::ClientCompanion->value,
            'version' => 1,
            'system_prompt' => 'Imported concurrent instructions.',
            'user_prompt_template' => '{{query}}',
            'variables_schema' => [],
            'parameter_config' => [],
            'context_policy' => [],
            'output_schema' => null,
            'allowed_tools' => [],
            'change_notes' => 'Concurrent import test',
        ];
        $importResults = Concurrency::driver('process')->run([
            static fn (): array => self::importPromptBundleInProcess($organization->id, $user->id, $bundle),
            static fn (): array => self::importPromptBundleInProcess($organization->id, $user->id, $bundle),
        ]);

        self::assertSame([], array_filter($importResults, static fn (array $result): bool => isset($result['error'])));
        self::assertSame([1, 2], collect($importResults)->pluck('version')->sort()->values()->all());
        self::assertSame(1, AiPrompt::query()
            ->where('organization_id', $organization->id)
            ->where('key', 'concurrent_import_prompt')
            ->count());
    }

    public function test_ai_composite_foreign_keys_restrict_parent_deletes_without_clearing_tenant_id(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Composite AI foreign-key lifecycle requires PostgreSQL semantics.');
        }

        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $user = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $credential = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'FK test credential',
            'revision_id' => (string) Str::uuid(),
        ]);
        $credential->organization_id = $organization->id;
        $credential->credentials = ['api_key' => 'fk-test'];
        $credential->save();
        $provider = AiProviderConfiguration::create([
            'organization_id' => $organization->id,
            'provider_name' => 'openai',
            'display_name' => 'OpenAI',
            'credential_id' => $credential->id,
        ]);
        $pricing = new AiPricingSnapshot(currency: 'USD', inputCostPerMillionMinorUnits: 15, outputCostPerMillionMinorUnits: 60);
        $model = AiModelConfiguration::create([
            'organization_id' => $organization->id,
            'provider_config_id' => $provider->id,
            'model_name' => 'gpt-fk-test',
            'display_name' => 'FK model',
            'capabilities' => [AiCapability::ClientCompanion->value],
            'pricing_snapshot' => $pricing->toArray(),
        ]);
        $release = AiModelRelease::create([
            'organization_id' => $organization->id,
            'model_config_id' => $model->id,
            'release_number' => 1,
            'provider_name' => 'openai',
            'model_name' => 'gpt-fk-test',
            'capabilities' => [AiCapability::ClientCompanion->value],
            'pricing_snapshot' => $pricing->toArray(),
        ]);
        $model->update(['active_release_id' => $release->id]);
        $prompt = AiPrompt::create([
            'organization_id' => $organization->id,
            'key' => 'fk_prompt',
            'name' => 'FK prompt',
            'capability' => AiCapability::ClientCompanion,
        ]);
        $version = AiPromptVersion::create([
            'organization_id' => $organization->id,
            'prompt_id' => $prompt->id,
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'FK test instructions.',
            'user_prompt_template' => '{{query}}',
        ]);
        $prompt->update(['active_version_id' => $version->id]);
        $run = AiRun::create([
            'organization_id' => $organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'fk_parent_delete',
            'initiated_by_user_id' => $user->id,
            'client_id' => $client->id,
            'prompt_id' => $prompt->id,
            'prompt_version_id' => $version->id,
            'model_config_id' => $model->id,
            'model_release_id' => $release->id,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);
        AiRunAttempt::create([
            'organization_id' => $organization->id,
            'ai_run_id' => $run->id,
            'attempt_number' => 1,
            'provider' => 'openai',
            'model' => 'gpt-fk-test',
            'model_release_id' => $release->id,
            'credential_id' => $credential->id,
            'status' => 'running',
            'budget_usage_date' => Carbon::now()->toDateString(),
            'pricing_snapshot' => $pricing->toArray(),
            'token_usage' => [],
        ]);
        AiEvalSuite::create([
            'organization_id' => $organization->id,
            'key' => 'fk_suite',
            'name' => 'FK suite',
            'capability' => AiCapability::ClientCompanion,
            'prompt_id' => $prompt->id,
        ]);

        $assertRestricted = static function (callable $delete): void {
            try {
                $delete();
                self::fail('Expected the composite foreign key to restrict parent deletion.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        };

        $assertRestricted(static fn (): int => DB::table('organization_credentials')->where('id', $credential->id)->delete());
        $assertRestricted(static fn (): int => DB::table('clients')->where('id', $client->id)->delete());
        $assertRestricted(static fn (): int => DB::table('ai_model_releases')->where('id', $release->id)->delete());
        $assertRestricted(static fn (): int => DB::table('ai_model_configurations')->where('id', $model->id)->delete());
        $assertRestricted(static fn (): int => DB::table('ai_prompt_versions')->where('id', $version->id)->delete());
        $assertRestricted(static fn (): int => DB::table('ai_prompts')->where('id', $prompt->id)->delete());

        self::assertSame($organization->id, DB::table('ai_runs')->where('id', $run->id)->value('organization_id'));
        self::assertSame($client->id, DB::table('ai_runs')->where('id', $run->id)->value('client_id'));
        self::assertSame($credential->id, DB::table('ai_run_attempts')->where('ai_run_id', $run->id)->value('credential_id'));
    }

    public function test_saved_credential_reassignment_locks_target_credential_before_provider_row(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Provider credential lock ordering requires PostgreSQL row locks.');
        }

        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $targetCredential = OrganizationCredential::factory()->forOrganization($organization)->create([
            'provider' => 'openai',
            'credential_name' => 'Target provider credential',
        ]);
        $currentCredential = OrganizationCredential::factory()->forOrganization($organization)->create([
            'provider' => 'openai',
            'credential_name' => 'Current provider credential',
        ]);
        $provider = AiProviderConfiguration::create([
            'organization_id' => $organization->id,
            'provider_name' => 'openai',
            'display_name' => 'OpenAI lock ordering',
            'credential_id' => $currentCredential->id,
            'health_status' => ProviderHealthStatus::Healthy,
            'tested_credential_revision' => $currentCredential->revision_id,
            'tested_configuration_digest' => 'digest-before-reassignment',
        ]);

        $token = (string) Str::uuid();
        $readyToken = 'ai-provider-ready-'.$token;
        $parentApplicationName = 'chuklov-m11d-ai-parent-'.$token;
        $childApplicationName = 'chuklov-m11d-ai-child-'.$token;
        $connection = DB::connection();
        $connection->statement(
            "select set_config('application_name', ?, false)",
            [$parentApplicationName],
        );
        $parentPid = (int) $connection->selectOne('select pg_backend_pid() as pid')->pid;
        $connection->beginTransaction();
        OrganizationCredential::query()
            ->where('organization_id', $organization->id)
            ->whereKey($targetCredential->id)
            ->lockForUpdate()
            ->firstOrFail();

        $pool = null;

        try {
            $command = ConsoleApplication::formatCommandString('invoke-serialized-closure');
            $task = static fn (): array => self::reassignProviderCredentialInProcess(
                $organization->id,
                $admin->id,
                $provider->id,
                $targetCredential->id,
                $childApplicationName,
                $readyToken,
            );
            $pool = app(ProcessFactory::class)->pool(function (Pool $pool) use ($task, $command): void {
                $pool->as('0')
                    ->path(base_path())
                    ->env([
                        'LARAVEL_INVOKABLE_CLOSURE' => base64_encode(serialize(new SerializableClosure($task))),
                    ])
                    ->timeout(30)
                    ->command($command);
            })->start();
            $processes = $pool->running();
            self::assertCount(1, $processes);
            $process = $processes->first();
            self::assertInstanceOf(InvokedProcess::class, $process);
            $readyOutput = '';
            $process->waitUntil(function (string $type, string $buffer) use (&$readyOutput, $readyToken): bool {
                if ($type !== SymfonyProcess::ERR) {
                    return false;
                }

                $readyOutput .= $buffer;

                return str_contains($readyOutput, $readyToken.PHP_EOL);
            });
            self::assertTrue($process->running());

            $this->waitForProviderCredentialLockWait($childApplicationName, $parentPid);
            $probe = Concurrency::driver('process')->run([
                static fn (): array => self::probeProviderRowLock($organization->id, $provider->id),
            ])[0];

            self::assertSame('free', $probe['status']);
            self::assertSame($currentCredential->id, $probe['credential_id']);
            self::assertTrue($process->running());

            $connection->commit();
            $processResult = $pool->wait()[0];
            self::assertInstanceOf(ProcessResult::class, $processResult);
            self::assertTrue($processResult->successful(), $processResult->errorOutput());
            $payload = json_decode($processResult->output(), true);
            self::assertIsArray($payload);
            self::assertTrue($payload['successful'] ?? false, $processResult->output());
            self::assertIsString($payload['result'] ?? null);
            $result = unserialize($payload['result']);
            self::assertIsArray($result);

            self::assertSame([
                'status' => 'completed',
                'credential_id' => $targetCredential->id,
            ], $result);
        } catch (ProcessTimedOutException $exception) {
            $this->writeProviderLockDiagnostics($childApplicationName, $parentPid);
            throw $exception;
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            if ($pool !== null && $pool->running()->isNotEmpty()) {
                $pool->stop(1);
            }
        }

        $provider->refresh();
        self::assertSame($targetCredential->id, $provider->credential_id);
        self::assertSame(ProviderHealthStatus::Unknown, $provider->health_status);
        self::assertNull($provider->tested_credential_revision);
        self::assertNull($provider->tested_configuration_digest);
        self::assertSame(2, OrganizationCredential::query()
            ->where('organization_id', $organization->id)
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
            'context_provenance' => ['retrieval_embedding' => EmbeddingExecutionSnapshot::active()->toArray()],
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

    /** @return array{status: string, credential_id: int}|array{status: string, exception: string} */
    private static function reassignProviderCredentialInProcess(
        int $organizationId,
        int $adminId,
        int $providerId,
        int $credentialId,
        string $applicationName,
        string $readyToken,
    ): array {
        try {
            self::setAiProcessApplicationName($applicationName);
            self::signalReady($readyToken);
            $organization = Organization::query()->findOrFail($organizationId);
            config()->set('tenancy.default_organization_id', $organizationId);
            app(OrganizationContext::class)->set($organization);
            $provider = AiProviderConfiguration::query()
                ->where('organization_id', $organizationId)
                ->whereKey($providerId)
                ->firstOrFail();
            $result = app(ConnectAiProvider::class)->update(
                User::query()->findOrFail($adminId),
                $provider,
                ['credential_id' => $credentialId],
            );

            return [
                'status' => 'completed',
                'credential_id' => (int) $result->credential_id,
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'error',
                'exception' => $exception::class,
            ];
        }
    }

    /** @return array{status: string, credential_id?: int, code?: string} */
    private static function probeProviderRowLock(int $organizationId, int $providerId): array
    {
        try {
            return DB::transaction(function () use ($organizationId, $providerId): array {
                $provider = AiProviderConfiguration::query()
                    ->where('organization_id', $organizationId)
                    ->whereKey($providerId)
                    ->lock('for update nowait')
                    ->firstOrFail();

                return [
                    'status' => 'free',
                    'credential_id' => (int) $provider->credential_id,
                ];
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '55P03') {
                return ['status' => 'locked'];
            }

            return [
                'status' => 'error',
                'code' => (string) $exception->getCode(),
            ];
        }
    }

    private function waitForProviderCredentialLockWait(string $childApplicationName, int $parentPid): void
    {
        $deadline = microtime(true) + 30;

        do {
            $activity = DB::selectOne(
                <<<'SQL'
SELECT pid, wait_event_type, wait_event, pg_blocking_pids(pid) AS blocking_pids
FROM pg_stat_activity
WHERE application_name = ?
SQL,
                [$childApplicationName],
            );

            if ($activity !== null
                && $activity->wait_event_type === 'Lock'
                && in_array($parentPid, self::blockingPids($activity->blocking_pids), true)) {
                return;
            }
        } while (microtime(true) < $deadline);

        $this->writeProviderLockDiagnostics($childApplicationName, $parentPid);
        self::fail('The provider reassignment process did not reach the target credential lock wait.');
    }

    /** @return list<int> */
    private static function blockingPids(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(
                array_map(static fn (mixed $pid): int => (int) $pid, $value),
                static fn (int $pid): bool => $pid > 0,
            ));
        }

        if (! is_string($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (string $pid): int => (int) trim($pid),
                explode(',', trim($value, '{}')),
            ),
            static fn (int $pid): bool => $pid > 0,
        ));
    }

    private static function setAiProcessApplicationName(string $applicationName): void
    {
        DB::statement(
            "select set_config('application_name', ?, false)",
            [$applicationName],
        );
    }

    private static function signalReady(string $readyToken): void
    {
        fwrite(STDERR, $readyToken.PHP_EOL);
        fflush(STDERR);
    }

    private function writeProviderLockDiagnostics(string $childApplicationName, int $parentPid): void
    {
        try {
            $activities = DB::select(
                <<<'SQL'
SELECT pid, application_name, state, wait_event_type, wait_event,
       pg_blocking_pids(pid) AS blocking_pids
FROM pg_stat_activity
WHERE application_name = ? OR pid = ?
ORDER BY pid
SQL,
                [$childApplicationName, $parentPid],
            );
            $locks = DB::select(
                <<<'SQL'
SELECT locks.pid, locks.locktype,
       CASE WHEN locks.relation IS NULL THEN NULL ELSE locks.relation::regclass::text END AS relation_name,
       locks.mode, locks.granted
FROM pg_locks AS locks
JOIN pg_stat_activity AS activity ON activity.pid = locks.pid
WHERE activity.application_name = ? OR activity.pid = ?
ORDER BY locks.pid, locks.granted, locks.locktype, relation_name, locks.mode
SQL,
                [$childApplicationName, $parentPid],
            );

            fwrite(STDERR, 'PostgreSQL provider lock diagnostics: '.json_encode([
                'activities' => array_map(static function (object $row): array {
                    $values = get_object_vars($row);

                    return [
                        'pid' => (int) ($values['pid'] ?? 0),
                        'application_name' => (string) ($values['application_name'] ?? ''),
                        'state' => $values['state'] ?? null,
                        'wait_event_type' => $values['wait_event_type'] ?? null,
                        'wait_event' => $values['wait_event'] ?? null,
                        'blocking_pids' => $values['blocking_pids'] ?? null,
                    ];
                }, $activities),
                'locks' => array_map(static function (object $row): array {
                    $values = get_object_vars($row);

                    return [
                        'pid' => (int) ($values['pid'] ?? 0),
                        'locktype' => (string) ($values['locktype'] ?? ''),
                        'relation' => $values['relation_name'] ?? null,
                        'mode' => (string) ($values['mode'] ?? ''),
                        'granted' => (bool) ($values['granted'] ?? false),
                    ];
                }, $locks),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
        } catch (\Throwable) {
            fwrite(STDERR, 'PostgreSQL provider lock diagnostics unavailable.'.PHP_EOL);
        }
    }

    /** @return array{run_id: int, queued_jobs: int} */
    private static function dispatchDuplicateAsyncRun(int $organizationId, int $userId, string $idempotencyKey): array
    {
        Queue::fake();
        $organization = Organization::query()->findOrFail($organizationId);
        config()->set('tenancy.default_organization_id', $organizationId);
        config()->set('rag.embedding.pricing', [
            'provider' => config('rag.embedding.provider'),
            'model' => config('rag.embedding.model'),
            'configuration_version' => config('rag.embedding.configuration_version'),
            'currency' => 'USD',
            'input_cost_per_million_minor_units' => 0,
            'zero_cost_local' => true,
        ]);
        app(OrganizationContext::class)->set($organization);
        $embeddingGenerator = new CountingInitialRagEmbeddingGenerator($organizationId);
        app()->instance(EmbeddingGenerator::class, $embeddingGenerator);
        app()->instance(KnowledgeRetriever::class, new CountingInitialRagRetriever($embeddingGenerator));
        app()->instance(
            AiContextAssemblerInterface::class,
            new CountingInitialRagContextAssembler(app(AiContextAssemblerInterface::class), $organizationId),
        );
        $user = User::query()->findOrFail($userId);
        $run = app(DispatchAsyncAiRun::class)->handle($user, new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'concurrent_async_idempotency',
            inputVariables: ['query' => 'concurrent retrieval'],
            idempotencyKey: $idempotencyKey,
        ));

        $queuedJobs = Queue::pushedJobs()[ProcessAiRunJob::class] ?? [];

        return ['run_id' => $run->id, 'queued_jobs' => count($queuedJobs)];
    }

    /** @return array{version: int}|array{error: string} */
    private static function createPromptDraftInProcess(int $organizationId, int $userId, int $promptId): array
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            config()->set('tenancy.default_organization_id', $organizationId);
            app(OrganizationContext::class)->set($organization);
            $version = app(CreatePromptDraft::class)->handle(
                User::query()->findOrFail($userId),
                $promptId,
                [
                    'system_prompt' => 'Concurrent draft instructions.',
                    'user_prompt_template' => '{{query}}',
                ],
            );

            return ['version' => $version->version];
        } catch (\Throwable $exception) {
            return ['error' => $exception::class];
        }
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array{version: int}|array{error: string}
     */
    private static function importPromptBundleInProcess(int $organizationId, int $userId, array $bundle): array
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            config()->set('tenancy.default_organization_id', $organizationId);
            app(OrganizationContext::class)->set($organization);
            $version = app(ImportPromptBundle::class)->handle(
                User::query()->findOrFail($userId),
                PromptBundle::fromArray($bundle),
            );

            return ['version' => $version->version];
        } catch (\Throwable $exception) {
            return ['error' => $exception::class];
        }
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
        config()->set('rag.embedding.pricing', [
            'provider' => config('rag.embedding.provider'),
            'model' => config('rag.embedding.model'),
            'configuration_version' => config('rag.embedding.configuration_version'),
            'currency' => 'USD',
            'input_cost_per_million_minor_units' => 0,
            'zero_cost_local' => true,
        ]);

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
