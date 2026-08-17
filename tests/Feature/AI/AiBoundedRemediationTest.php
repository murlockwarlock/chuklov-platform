<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\PrepareAiRun;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Exceptions\AiPricingProfileIncompleteException;
use App\Modules\AI\Domain\Exceptions\AiRagRetrievalException;
use App\Modules\AI\Domain\Exceptions\AiToolExecutionFencedException;
use App\Modules\AI\Domain\Models\AiOrganizationDailyBudget;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\ValueObjects\AiContextPolicy;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Domain\ValueObjects\AiRunExecutionContext;
use App\Modules\AI\Infrastructure\Context\AiContextAssembler;
use App\Modules\AI\Infrastructure\Tools\SearchKnowledgeBaseSdkTool;
use App\Modules\AI\Infrastructure\Tools\SearchKnowledgeBaseTool;
use App\Modules\Knowledge\Application\Data\RetrievalQuery;
use App\Modules\Knowledge\Domain\Contracts\KnowledgeRetriever;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

final class AiBoundedRemediationTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_rag_receives_the_absolute_deadline_and_remaining_tool_budget(): void
    {
        $deadline = Carbon::now()->addSeconds(9);
        $captured = null;
        $retriever = new class($captured) implements KnowledgeRetriever
        {
            public function __construct(private mixed &$captured) {}

            public function retrieve(User $actor, RetrievalQuery $query): array
            {
                return $this->retrieveForOrganization(1, $query);
            }

            public function retrieveForOrganization(int|string $organizationId, RetrievalQuery $query): array
            {
                $this->captured = $query;

                return [];
            }
        };

        $result = (new AiContextAssembler($retriever))->assemble(
            organizationId: 1,
            policy: new AiContextPolicy(includeRag: true),
            inputVariables: ['query' => 'bounded retrieval'],
            inputReferences: [],
            executionDeadlineAt: $deadline,
        );

        self::assertSame([], $result->ragChunks);
        Assert::assertInstanceOf(RetrievalQuery::class, $captured);
        self::assertSame($deadline->getPreciseTimestamp(6), $captured->executionDeadlineAt?->getPreciseTimestamp(6));
        self::assertGreaterThan(0, $captured->executionTimeoutSeconds);
        self::assertLessThanOrEqual(30, $captured->executionTimeoutSeconds);
        self::assertLessThanOrEqual(9, $captured->executionTimeoutSeconds);
    }

    public function test_missing_embedding_pricing_fails_before_external_rag_call(): void
    {
        config()->set('rag.embedding.pricing.input_cost_per_million_minor_units', null);
        config()->set('rag.embedding.pricing.zero_cost_local', false);
        $calls = 0;
        $retriever = new class($calls) implements KnowledgeRetriever
        {
            public function __construct(private int &$calls) {}

            public function retrieve(User $actor, RetrievalQuery $query): array
            {
                return [];
            }

            public function retrieveForOrganization(int|string $organizationId, RetrievalQuery $query): array
            {
                $this->calls++;

                return [];
            }
        };

        try {
            (new AiContextAssembler($retriever))->assemble(
                organizationId: 1,
                policy: new AiContextPolicy(includeRag: true),
                inputVariables: ['query' => 'priced retrieval'],
                inputReferences: [],
                executionDeadlineAt: Carbon::now()->addSeconds(30),
            );
            self::fail('Expected missing embedding pricing to fail closed.');
        } catch (AiRagRetrievalException $exception) {
            self::assertSame('configuration', $exception->reason);
        }

        self::assertSame(0, $calls);
    }

    public function test_immutable_billing_profile_prices_cache_reasoning_and_fixed_request_meters(): void
    {
        $pricing = new AiPricingSnapshot(
            currency: 'USD',
            inputCostPerMillionMinorUnits: 100,
            outputCostPerMillionMinorUnits: 200,
            cacheReadInputCostPerMillionMinorUnits: 300,
            cacheWriteInputCostPerMillionMinorUnits: 400,
            reasoningCostPerMillionMinorUnits: 500,
            fixedRequestCostApplicable: true,
            fixedRequestCostMinorUnits: 7,
        );

        self::assertTrue($pricing->isComplete());
        self::assertSame(
            1514,
            $pricing->calculateCostMinorUnits(
                promptTokens: 1_000_000,
                completionTokens: 1_000_000,
                cacheReadInputTokens: 1_000_000,
                cacheWriteInputTokens: 1_000_000,
                reasoningTokens: 1_000_000,
                providerRequests: 2,
            ),
        );
    }

    public function test_incomplete_profile_and_unpriced_meter_fail_closed(): void
    {
        $pricing = AiPricingSnapshot::fromArray([
            'currency' => 'USD',
            'input_cost_per_million_minor_units' => 100,
            'output_cost_per_million_minor_units' => 200,
        ]);

        self::assertFalse($pricing->isComplete());
        $this->expectException(AiPricingProfileIncompleteException::class);
        $pricing->calculateCostMinorUnits(1, 1, cacheReadInputTokens: 1);
    }

    public function test_explicitly_unsupported_billing_meter_fails_closed(): void
    {
        $pricing = new AiPricingSnapshot(unsupportedMeters: ['provider_surcharge']);

        self::assertFalse($pricing->isComplete());
        $this->expectException(AiPricingProfileIncompleteException::class);
        $pricing->assertComplete();
    }

    public function test_tool_query_is_rejected_before_retriever_execution_when_over_bound(): void
    {
        $calls = 0;
        $retriever = new class($calls) implements KnowledgeRetriever
        {
            public function __construct(private int &$calls) {}

            public function retrieve(User $actor, RetrievalQuery $query): array
            {
                return [];
            }

            public function retrieveForOrganization(int|string $organizationId, RetrievalQuery $query): array
            {
                $this->calls++;

                return [];
            }
        };

        try {
            (new SearchKnowledgeBaseTool($retriever))->execute(
                organizationId: 1,
                input: ['query' => str_repeat('x', 4001)],
            );
            self::fail('Expected the bounded query validation to fail.');
        } catch (AiRagRetrievalException $exception) {
            self::assertSame('configuration', $exception->reason);
        }

        self::assertSame(0, $calls);
    }

    public function test_rag_reservation_includes_initial_and_maximum_tool_embedding_exposure(): void
    {
        config()->set('rag.embedding.pricing', [
            'provider' => config('rag.embedding.provider'),
            'model' => config('rag.embedding.model'),
            'configuration_version' => config('rag.embedding.configuration_version'),
            'currency' => 'USD',
            'input_cost_per_million_minor_units' => 1000,
            'zero_cost_local' => false,
        ]);

        $organization = Organization::factory()->create();
        $prompt = AiPrompt::query()->create([
            'organization_id' => $organization->id,
            'key' => 'bounded_reservation_prompt',
            'name' => 'Bounded reservation prompt',
            'capability' => AiCapability::ClientCompanion,
        ]);
        $version = AiPromptVersion::query()->create([
            'organization_id' => $organization->id,
            'prompt_id' => $prompt->id,
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'Bounded instructions',
            'user_prompt_template' => '{{query}}',
            'context_policy' => ['include_rag' => true],
            'allowed_tools' => ['search_knowledge_base'],
            'activated_at' => Carbon::now(),
        ]);

        $claim = app(PrepareAiRun::class)->claim(
            organizationId: $organization->id,
            request: new AiRunRequest(
                capability: AiCapability::ClientCompanion,
                workflowKey: 'bounded_reservation',
                inputVariables: ['query' => 'initial retrieval'],
            ),
            promptVersion: $version,
            contextPolicy: new AiContextPolicy(includeRag: true),
            executionDeadlineAt: Carbon::now()->addSeconds(2640),
            maxToolCalls: 5,
        );

        $run = $claim['run'];
        self::assertSame(AiRunStatus::Preparing, $run->status);
        self::assertSame(96, $run->retrieval_embedding_reserved_cost_minor_units);
        self::assertSame('reserved', $run->retrieval_embedding_budget_status);
        self::assertSame(96, AiOrganizationDailyBudget::query()->where('organization_id', $organization->id)->value('reserved_minor_units'));
        self::assertSame(96, data_get($run->context_provenance, 'retrieval_embedding.maximum_cost_minor_units'));

        $toolOnlyClaim = app(PrepareAiRun::class)->claim(
            organizationId: $organization->id,
            request: new AiRunRequest(
                capability: AiCapability::ClientCompanion,
                workflowKey: 'bounded_tool_only_reservation',
                inputVariables: [],
            ),
            promptVersion: $version,
            contextPolicy: new AiContextPolicy(includeRag: false),
            executionDeadlineAt: Carbon::now()->addSeconds(2640),
            maxToolCalls: 5,
        );

        self::assertSame(80, $toolOnlyClaim['run']->retrieval_embedding_reserved_cost_minor_units);
    }

    public function test_tool_passes_one_deadline_of_at_most_thirty_seconds_to_retrieval(): void
    {
        $startedAt = Carbon::parse('2030-01-01 00:00:00.000000');
        Carbon::setTestNow($startedAt);
        $organization = Organization::factory()->create();
        $token = (string) Str::uuid();
        $runDeadline = $startedAt->copy()->addSeconds(60);
        $run = AiRun::query()->create([
            'organization_id' => $organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'shared_tool_deadline',
            'status' => AiRunStatus::Running,
            'worker_lease_token' => $token,
            'worker_lease_expires_at' => $runDeadline,
            'execution_deadline_at' => $runDeadline,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);
        $captured = null;
        $retriever = new class($captured) implements KnowledgeRetriever
        {
            public function __construct(private mixed &$captured) {}

            public function retrieve(User $actor, RetrievalQuery $query): array
            {
                return $this->retrieveForOrganization(1, $query);
            }

            public function retrieveForOrganization(int|string $organizationId, RetrievalQuery $query): array
            {
                $this->captured = $query;

                return [];
            }
        };

        try {
            $response = (string) (new SearchKnowledgeBaseSdkTool(
                executionContext: new AiRunExecutionContext($organization->id, $run->id, $token, $runDeadline),
                domainTool: new SearchKnowledgeBaseTool($retriever),
                maxToolCalls: 1,
            ))->handle(new Request(['query' => 'shared deadline']));
        } finally {
            Carbon::setTestNow();
        }

        self::assertSame('No relevant knowledge base records found.', $response);
        Assert::assertInstanceOf(RetrievalQuery::class, $captured);
        self::assertSame(
            $startedAt->copy()->addSeconds(30)->getPreciseTimestamp(6),
            $captured->executionDeadlineAt?->getPreciseTimestamp(6),
        );
        self::assertSame(30, $captured->executionTimeoutSeconds);
    }

    public function test_tool_cannot_consume_two_independent_thirty_second_windows(): void
    {
        $startedAt = Carbon::parse('2030-01-01 00:00:00.000000');
        Carbon::setTestNow($startedAt);
        $organization = Organization::factory()->create();
        $token = (string) Str::uuid();
        $runDeadline = $startedAt->copy()->addSeconds(60);
        $run = AiRun::query()->create([
            'organization_id' => $organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'single_tool_window',
            'status' => AiRunStatus::Running,
            'worker_lease_token' => $token,
            'worker_lease_expires_at' => $runDeadline,
            'execution_deadline_at' => $runDeadline,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);
        $captured = null;
        $retriever = new class($captured, $startedAt) implements KnowledgeRetriever
        {
            public function __construct(private mixed &$captured, private Carbon $startedAt) {}

            public function retrieve(User $actor, RetrievalQuery $query): array
            {
                return $this->retrieveForOrganization(1, $query);
            }

            public function retrieveForOrganization(int|string $organizationId, RetrievalQuery $query): array
            {
                $this->captured = $query;
                Carbon::setTestNow($this->startedAt->copy()->addSeconds(31));

                return [];
            }
        };

        try {
            $this->expectException(AiToolExecutionFencedException::class);
            (new SearchKnowledgeBaseSdkTool(
                executionContext: new AiRunExecutionContext($organization->id, $run->id, $token, $runDeadline),
                domainTool: new SearchKnowledgeBaseTool($retriever),
                maxToolCalls: 1,
            ))->handle(new Request(['query' => 'embedding then database']));
        } finally {
            Carbon::setTestNow();
        }

        Assert::assertInstanceOf(RetrievalQuery::class, $captured);
        self::assertSame(
            $startedAt->copy()->addSeconds(30)->getPreciseTimestamp(6),
            $captured->executionDeadlineAt?->getPreciseTimestamp(6),
        );
        self::assertSame(0, $run->fresh()->ragReferences()->count());
        self::assertNotSame('succeeded', $run->toolCalls()->firstOrFail()->execution_status);
    }

    public function test_tool_cannot_finalize_successful_retrieval_after_absolute_deadline(): void
    {
        $organization = Organization::factory()->create();
        $token = (string) Str::uuid();
        $deadline = Carbon::now()->addSeconds(5);
        $run = AiRun::query()->create([
            'organization_id' => $organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'deadline_tool_fence',
            'status' => AiRunStatus::Running,
            'worker_lease_token' => $token,
            'worker_lease_expires_at' => Carbon::now()->addMinutes(5),
            'execution_deadline_at' => $deadline,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);
        $retriever = new class($deadline) implements KnowledgeRetriever
        {
            public function __construct(private Carbon $deadline) {}

            public function retrieve(User $actor, RetrievalQuery $query): array
            {
                return [];
            }

            public function retrieveForOrganization(int|string $organizationId, RetrievalQuery $query): array
            {
                Carbon::setTestNow($this->deadline->copy()->addSecond());

                return [];
            }
        };

        try {
            $this->expectException(AiToolExecutionFencedException::class);
            (new SearchKnowledgeBaseSdkTool(
                executionContext: new AiRunExecutionContext($organization->id, $run->id, $token, $deadline),
                domainTool: new SearchKnowledgeBaseTool($retriever),
                maxToolCalls: 1,
            ))->handle(new Request(['query' => 'deadline retrieval']));
        } finally {
            Carbon::setTestNow();
        }

        self::assertSame(0, $run->fresh()->ragReferences()->count());
    }
}
