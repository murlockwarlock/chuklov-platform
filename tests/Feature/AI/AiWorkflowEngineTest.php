<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\DispatchAsyncAiRun;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\BudgetReservationStatus;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Exceptions\AiKillSwitchException;
use App\Modules\AI\Domain\Exceptions\AiProviderUnavailableException;
use App\Modules\AI\Domain\Exceptions\AiRagRetrievalException;
use App\Modules\AI\Domain\Exceptions\AiToolLimitExceededException;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiOrganizationDailyBudget;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunAttempt;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\AI\Domain\Models\AiRunToolCall;
use App\Modules\AI\Domain\ValueObjects\AiContextPolicy;
use App\Modules\AI\Domain\ValueObjects\AiInputReference;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Infrastructure\Context\AiContextAssembler;
use App\Modules\AI\Infrastructure\Engine\DynamicWorkflowAgent;
use App\Modules\AI\Infrastructure\Jobs\ProcessAiRunJob;
use App\Modules\AI\Infrastructure\Tools\SearchKnowledgeBaseSdkTool;
use App\Modules\AI\Infrastructure\Tools\SearchKnowledgeBaseTool;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Knowledge\Application\Data\RetrievalQuery;
use App\Modules\Knowledge\Application\Data\RetrievalResult;
use App\Modules\Knowledge\Domain\Contracts\KnowledgeRetriever;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class AiWorkflowEngineTest extends TestCase
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

    private function setupConfiguredModel(AiCapability $capability, string $providerName = 'openai', string $modelName = 'gpt-4o-mini', int $priority = 1, bool $enabled = true): AiModelConfiguration
    {
        $credential = new OrganizationCredential([
            'provider' => $providerName,
            'credential_name' => "{$providerName} Production",
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
            'is_enabled' => $enabled,
            'credential_id' => $credential->id,
        ]);

        $pricing = new AiPricingSnapshot(currency: 'USD', inputCostPerMillionMinorUnits: 15, outputCostPerMillionMinorUnits: 60);

        $model = AiModelConfiguration::create([
            'organization_id' => $this->organization->id,
            'provider_config_id' => $provider->id,
            'model_name' => $modelName,
            'display_name' => strtoupper($modelName),
            'is_enabled' => $enabled,
            'capabilities' => [$capability->value],
            'pricing_snapshot' => $pricing->toArray(),
            'failover_priority' => $priority,
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

        return $model;
    }

    public function test_workflow_engine_executes_run_and_creates_encrypted_class_c_payload(): void
    {
        DynamicWorkflowAgent::fake(['{"summary": "Извлеченные факты", "document_type": "epicrisis", "extracted_facts": ["Анамнез без особенностей"]}']);

        $model = $this->setupConfiguredModel(AiCapability::ClinicalDocumentExtraction);

        $prompt = AiPrompt::create([
            'organization_id' => $this->organization->id,
            'key' => 'clinical_doc_extract',
            'name' => 'Извлечение документа',
            'capability' => AiCapability::ClinicalDocumentExtraction,
        ]);

        $version = AiPromptVersion::create([
            'organization_id' => $this->organization->id,
            'prompt_id' => $prompt->id,
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'Извлеките данные из документа.',
            'user_prompt_template' => 'Документ: {{document_text}}',
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string'],
                    'document_type' => ['type' => 'string'],
                    'extracted_facts' => ['type' => 'array'],
                ],
                'required' => ['summary', 'document_type', 'extracted_facts'],
            ],
            'activated_at' => Carbon::now(),
        ]);
        $prompt->update(['active_version_id' => $version->id]);

        $client = new Client([
            'full_name' => 'Иван Иванов',
        ]);
        $client->organization_id = max(0, (int) $this->organization->id);
        $client->save();

        /** @var AiWorkflowEngine $engine */
        $engine = app(AiWorkflowEngine::class);

        $request = new AiRunRequest(
            capability: AiCapability::ClinicalDocumentExtraction,
            workflowKey: 'doc_extraction_test',
            origin: AiRunOrigin::User,
            initiatedByUserId: $this->user->id,
            clientId: $client->id,
            inputVariables: ['document_text' => 'Тестовый текст выписки'],
            inputReferences: [new AiInputReference('client', $client->id)],
        );

        $result = $engine->run($this->organization->id, $request);

        $this->assertTrue($result->isSuccess());
        $this->assertNotNull($result->outputPayload);
        $this->assertSame('epicrisis', $result->outputPayload['document_type']);

        $run = AiRun::find($result->runId);
        $this->assertNotNull($run);
        $this->assertSame(AiRunStatus::Succeeded, $run->status);
        $this->assertSame(HumanReviewStatus::PendingReview, $run->human_review_status);
        $this->assertSame('openai', $run->actual_provider);
        $this->assertSame('gpt-4o-mini', $run->actual_model);

        // Verify protected trace on ai_run_payloads is encrypted
        $payload = AiRunPayload::where('ai_run_id', $run->id)->first();
        $this->assertNotNull($payload);
        $this->assertNotNull($payload->encrypted_output_text);
        $this->assertNotSame('{"summary": "Извлеченные факты", "document_type": "epicrisis", "extracted_facts": ["Анамнез без особенностей"]}', $payload->encrypted_output_text);

        // Verify decryption using MedicalEncryptorInterface
        $encryptor = app(MedicalEncryptorInterface::class);
        $decrypted = $encryptor->decryptField($this->organization->id, $payload->encrypted_output_text, $payload->encryption_key_version);
        $this->assertStringContainsString('epicrisis', (string) $decrypted);

        // Verify attempt snapshots credential_revision
        $attempt = AiRunAttempt::where('ai_run_id', $run->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(BudgetReservationStatus::Settled, $attempt->budget_reservation_status);
        $this->assertNotNull($attempt->settled_estimated_cost_minor_units);
    }

    public function test_provider_and_model_selection_drives_actual_invocation(): void
    {
        DynamicWorkflowAgent::fake(['Anthropic model response']);

        $this->setupConfiguredModel(
            capability: AiCapability::ClientCompanion,
            providerName: 'anthropic',
            modelName: 'claude-3-5-sonnet',
            priority: 1,
        );

        /** @var AiWorkflowEngine $engine */
        $engine = app(AiWorkflowEngine::class);

        $request = new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'provider_binding_test',
            inputVariables: ['query' => 'Hello'],
        );

        $result = $engine->run($this->organization->id, $request);

        $this->assertTrue($result->isSuccess());

        $run = AiRun::find($result->runId);
        $this->assertNotNull($run);
        $this->assertSame('anthropic', $run->actual_provider);
        $this->assertSame('claude-3-5-sonnet', $run->actual_model);

        $attempt = AiRunAttempt::where('ai_run_id', $run->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame('anthropic', $attempt->provider);
        $this->assertSame('claude-3-5-sonnet', $attempt->model);
    }

    public function test_disabled_candidate_is_skipped_and_failover_invokes_next_candidate(): void
    {
        DynamicWorkflowAgent::fake(['Response from second enabled provider']);

        // Candidate 1: Disabled
        $this->setupConfiguredModel(
            capability: AiCapability::ClientCompanion,
            providerName: 'openai',
            modelName: 'gpt-4o',
            priority: 1,
            enabled: false,
        );

        // Candidate 2: Enabled
        $this->setupConfiguredModel(
            capability: AiCapability::ClientCompanion,
            providerName: 'anthropic',
            modelName: 'claude-3-5-haiku',
            priority: 2,
            enabled: true,
        );

        /** @var AiWorkflowEngine $engine */
        $engine = app(AiWorkflowEngine::class);

        $request = new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'failover_test',
            inputVariables: ['query' => 'Test query'],
        );

        $result = $engine->run($this->organization->id, $request);

        $this->assertTrue($result->isSuccess());

        $run = AiRun::find($result->runId);
        $this->assertNotNull($run);
        $this->assertSame('anthropic', $run->actual_provider);
        $this->assertSame('claude-3-5-haiku', $run->actual_model);
    }

    public function test_no_configured_candidate_fails_closed_without_provider_call(): void
    {
        // No enabled model candidate in database!
        /** @var AiWorkflowEngine $engine */
        $engine = app(AiWorkflowEngine::class);

        $request = new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'no_candidate_test',
            inputVariables: ['query' => 'Hello'],
        );

        $this->expectException(AiProviderUnavailableException::class);
        $engine->run($this->organization->id, $request);
    }

    public function test_rag_context_uses_actual_retrieved_content_not_source_reference(): void
    {
        $fakeRetriever = new class implements KnowledgeRetriever
        {
            public function retrieve(User $actor, RetrievalQuery $query): array
            {
                return $this->retrieveForOrganization(1, $query);
            }

            public function retrieveForOrganization(int|string $organizationId, RetrievalQuery $query): array
            {
                return [
                    new RetrievalResult(
                        chunkId: 101,
                        sourceId: 5,
                        sourceTitle: 'Clinical Handbook',
                        sourceType: 'authored_text',
                        revisionId: 2,
                        revisionVersion: 1,
                        chunkIndex: 0,
                        content: 'Real clinical paragraph about rehabilitation.',
                        similarity: 0.92,
                        sourceReference: 'Book X, Chapter 3, p. 45',
                        startOffset: 0,
                        endOffset: 50,
                        ingestionRunId: 1,
                        embeddingConfigurationKey: 'emb_config_key_123',
                    ),
                ];
            }
        };

        $assembler = new AiContextAssembler(knowledgeRetriever: $fakeRetriever);
        $policy = new AiContextPolicy(includeRag: true, ragMaxChunks: 3);

        $result = $assembler->assemble(
            organizationId: $this->organization->id,
            policy: $policy,
            inputVariables: ['query' => 'rehabilitation'],
            inputReferences: [],
        );

        // Assert that the real content reaches the context variables
        $this->assertStringContainsString('Real clinical paragraph about rehabilitation.', $result->variables['rag_context']);
        $this->assertStringNotContainsString('Book X, Chapter 3, p. 45', $result->variables['rag_context']);

        // Assert that provenance stores config key
        $this->assertSame('emb_config_key_123', $result->ragChunks[0]->embeddingConfigurationKey);
    }

    public function test_search_knowledge_tool_works_in_queue_context_without_web_auth_state(): void
    {
        Auth::logout(); // Ensure no web user is logged in!

        $fakeRetriever = new class implements KnowledgeRetriever
        {
            public function retrieve(User $actor, RetrievalQuery $query): array
            {
                return $this->retrieveForOrganization(1, $query);
            }

            public function retrieveForOrganization(int|string $organizationId, RetrievalQuery $query): array
            {
                return [
                    new RetrievalResult(
                        chunkId: 202,
                        sourceId: 7,
                        sourceTitle: 'Post-op Protocol',
                        sourceType: 'authored_text',
                        revisionId: 3,
                        revisionVersion: 1,
                        chunkIndex: 1,
                        content: 'Post-operative exercise guidelines.',
                        similarity: 0.88,
                        sourceReference: 'Doc Section 4',
                        startOffset: 50,
                        endOffset: 120,
                        ingestionRunId: 2,
                        embeddingConfigurationKey: 'cfg_789',
                    ),
                ];
            }
        };

        $tool = new SearchKnowledgeBaseTool(knowledgeRetriever: $fakeRetriever);

        $res = $tool->execute($this->organization->id, ['query' => 'exercise']);

        $this->assertSame(1, $res['count']);
        $this->assertSame('Post-operative exercise guidelines.', $res['results'][0]['content']);
        $this->assertSame('Doc Section 4', $res['results'][0]['source_reference']);
        $this->assertSame('cfg_789', $res['results'][0]['embedding_configuration_key']);
    }

    public function test_real_sdk_tool_execution_creates_durable_ai_run_tool_call(): void
    {
        $fakeRetriever = new class implements KnowledgeRetriever
        {
            public function retrieve(User $actor, RetrievalQuery $query): array
            {
                return $this->retrieveForOrganization(1, $query);
            }

            public function retrieveForOrganization(int|string $organizationId, RetrievalQuery $query): array
            {
                return [
                    new RetrievalResult(
                        chunkId: 303,
                        sourceId: 9,
                        sourceTitle: 'Rehab Guidelines',
                        sourceType: 'authored_text',
                        revisionId: 1,
                        revisionVersion: 1,
                        chunkIndex: 0,
                        content: 'Shoulder rehabilitation protocols.',
                        similarity: 0.95,
                        sourceReference: 'Section 1',
                        startOffset: 0,
                        endOffset: 34,
                        ingestionRunId: 1,
                        embeddingConfigurationKey: 'key_1',
                    ),
                ];
            }
        };

        $domainTool = new SearchKnowledgeBaseTool(knowledgeRetriever: $fakeRetriever);

        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'tool_test',
            'status' => AiRunStatus::Running,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);

        $sdkTool = new SearchKnowledgeBaseSdkTool(
            organizationId: $this->organization->id,
            runId: $run->id,
            domainTool: $domainTool,
            maxToolCalls: 5,
        );

        $request = new Request(['query' => 'shoulder']);
        $response = (string) $sdkTool->handle($request);

        $this->assertStringContainsString('Shoulder rehabilitation protocols.', $response);

        // Verify AiRunToolCall was persisted
        $call = AiRunToolCall::where('ai_run_id', $run->id)->first();
        $this->assertNotNull($call);
        $this->assertSame(1, $call->call_index);
        $this->assertSame('search_knowledge_base', $call->tool_name);
        $this->assertTrue($call->is_read_only);
        $this->assertSame('succeeded', $call->execution_status);
        $this->assertSame(hash('sha256', (string) json_encode(['query' => 'shoulder'])), $call->input_digest);
    }

    public function test_max_tool_calls_per_run_fails_closed_and_persists_failure_provenance(): void
    {
        $fakeRetriever = new class implements KnowledgeRetriever
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

        $domainTool = new SearchKnowledgeBaseTool(knowledgeRetriever: $fakeRetriever);

        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'tool_limit_test',
            'status' => AiRunStatus::Running,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);

        $sdkTool = new SearchKnowledgeBaseSdkTool(
            organizationId: $this->organization->id,
            runId: $run->id,
            domainTool: $domainTool,
            maxToolCalls: 1, // Limit = 1
        );

        // Call 1: Succeeded
        $sdkTool->handle(new Request(['query' => 'q1']));

        // Call 2: Must throw AiToolLimitExceededException
        $this->expectException(AiToolLimitExceededException::class);
        $sdkTool->handle(new Request(['query' => 'q2']));

        $failedCall = AiRunToolCall::where('ai_run_id', $run->id)->where('call_index', 2)->first();
        $this->assertNotNull($failedCall);
        $this->assertSame('failed', $failedCall->execution_status);
    }

    public function test_capability_tool_allowlist_enforcement(): void
    {
        // ClinicalDocumentExtraction has allowedTools = []
        $this->setupConfiguredModel(AiCapability::ClinicalDocumentExtraction);

        DynamicWorkflowAgent::fake(['{"summary": "Doc", "document_type": "epicrisis", "extracted_facts": []}']);

        /** @var AiWorkflowEngine $engine */
        $engine = app(AiWorkflowEngine::class);

        $result = $engine->run($this->organization->id, new AiRunRequest(
            capability: AiCapability::ClinicalDocumentExtraction,
            workflowKey: 'no_tool_cap_test',
            inputVariables: ['document_text' => 'Текст'],
        ));

        $this->assertTrue($result->isSuccess());

        // Zero tool calls must have been made
        $toolCallsCount = AiRunToolCall::where('ai_run_id', $result->runId)->count();
        $this->assertSame(0, $toolCallsCount);
    }

    public function test_prompt_cannot_enable_tool_forbidden_by_capability(): void
    {
        $this->setupConfiguredModel(AiCapability::ClinicalDocumentExtraction);

        $prompt = AiPrompt::create([
            'organization_id' => $this->organization->id,
            'key' => 'doc_extract_illegal_tool',
            'name' => 'Extract with illegal tool',
            'capability' => AiCapability::ClinicalDocumentExtraction,
        ]);

        // Attempting to declare 'search_knowledge_base' on a capability that forbids tools
        $version = AiPromptVersion::create([
            'organization_id' => $this->organization->id,
            'prompt_id' => $prompt->id,
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'Extract',
            'user_prompt_template' => '{{document_text}}',
            'allowed_tools' => ['search_knowledge_base'],
            'activated_at' => Carbon::now(),
        ]);
        $prompt->update(['active_version_id' => $version->id]);

        DynamicWorkflowAgent::fake(['{"summary": "Doc", "document_type": "epicrisis", "extracted_facts": []}']);

        /** @var AiWorkflowEngine $engine */
        $engine = app(AiWorkflowEngine::class);

        $result = $engine->run($this->organization->id, new AiRunRequest(
            capability: AiCapability::ClinicalDocumentExtraction,
            workflowKey: 'illegal_tool_test',
            promptVersionId: $version->id,
            inputVariables: ['document_text' => 'Текст'],
        ));

        $this->assertTrue($result->isSuccess());

        // Still 0 tools because capability allowlist takes strict intersection
        $toolCallsCount = AiRunToolCall::where('ai_run_id', $result->runId)->count();
        $this->assertSame(0, $toolCallsCount);
    }

    public function test_rate_limit_per_minute_enforced_fail_closed(): void
    {
        $this->setupConfiguredModel(AiCapability::ClientCompanion);

        AiOrganizationSafetyControl::create([
            'organization_id' => $this->organization->id,
            'is_ai_globally_enabled' => true,
            'max_runs_per_minute' => 1, // Limit = 1 run per minute
        ]);

        RateLimiter::clear("ai:org:{$this->organization->id}:runs_per_minute");

        DynamicWorkflowAgent::fake(['Response 1', 'Response 2']);

        /** @var AiWorkflowEngine $engine */
        $engine = app(AiWorkflowEngine::class);

        // Run 1: Allowed
        $res1 = $engine->run($this->organization->id, new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'rate_1',
            inputVariables: ['query' => 'Q1'],
        ));
        $this->assertTrue($res1->isSuccess());

        // Run 2: Exceeds rate limit -> Failed closed
        $res2 = $engine->run($this->organization->id, new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'rate_2',
            inputVariables: ['query' => 'Q2'],
        ));
        $this->assertFalse($res2->isSuccess());
        $this->assertSame(AiRunStatus::Failed, $res2->status);
        $this->assertSame('rate_limited', $res2->errorCategory?->value);
    }

    public function test_rag_required_fails_closed_when_retrieval_throws_exception(): void
    {
        $failingRetriever = new class implements KnowledgeRetriever
        {
            public function retrieve(User $actor, RetrievalQuery $query): array
            {
                throw new \RuntimeException('Vector database connection refused');
            }

            public function retrieveForOrganization(int|string $organizationId, RetrievalQuery $query): array
            {
                throw new \RuntimeException('Vector database connection refused');
            }
        };

        $assembler = new AiContextAssembler(knowledgeRetriever: $failingRetriever);
        $policy = new AiContextPolicy(includeRag: true, requireGroundedRag: true);

        $this->expectException(AiRagRetrievalException::class);
        $assembler->assemble(
            organizationId: $this->organization->id,
            policy: $policy,
            inputVariables: ['query' => 'clinical protocol'],
            inputReferences: [],
        );
    }

    public function test_rag_degradation_allowed_by_policy_continues_with_degraded_flag(): void
    {
        $failingRetriever = new class implements KnowledgeRetriever
        {
            public function retrieve(User $actor, RetrievalQuery $query): array
            {
                throw new \RuntimeException('Transient network timeout');
            }

            public function retrieveForOrganization(int|string $organizationId, RetrievalQuery $query): array
            {
                throw new \RuntimeException('Transient network timeout');
            }
        };

        $assembler = new AiContextAssembler(knowledgeRetriever: $failingRetriever);
        $policy = new AiContextPolicy(includeRag: true, requireGroundedRag: false, allowRagDegradation: true);

        $result = $assembler->assemble(
            organizationId: $this->organization->id,
            policy: $policy,
            inputVariables: ['query' => 'rehab advice'],
            inputReferences: [],
        );

        $this->assertTrue($result->provenanceSummary['rag_degraded']);
        $this->assertSame('', $result->variables['rag_context'] ?? '');
    }

    public function test_async_idempotency_returns_existing_run_without_duplicate_job(): void
    {
        Queue::fake();

        $dispatcher = app(DispatchAsyncAiRun::class);

        $request = new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'async_idempotent_test',
            idempotencyKey: 'async-idem-key-999',
            inputVariables: ['query' => 'Test async'],
        );

        // 1. First async dispatch
        $run1 = $dispatcher->handle($this->user, $request);
        $this->assertSame('async-idem-key-999', $run1->idempotency_key);

        // 2. Second async dispatch with same idempotency key
        $run2 = $dispatcher->handle($this->user, $request);
        $this->assertSame($run1->id, $run2->id);

        // 3. Verify exactly 1 job was dispatched to queue
        Queue::assertPushed(ProcessAiRunJob::class, 1);
    }

    public function test_workflow_engine_fails_closed_when_daily_spend_budget_exceeded(): void
    {
        $this->setupConfiguredModel(AiCapability::ClientCompanion);

        AiOrganizationSafetyControl::create([
            'organization_id' => $this->organization->id,
            'is_ai_globally_enabled' => true,
            'max_daily_spend_minor_units' => 10,
        ]);

        AiOrganizationDailyBudget::create([
            'organization_id' => $this->organization->id,
            'usage_date' => Carbon::now()->toDateString(),
            'spent_minor_units' => 10,
            'reserved_minor_units' => 0,
        ]);

        /** @var AiWorkflowEngine $engine */
        $engine = app(AiWorkflowEngine::class);

        $request = new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'budget_test',
            inputVariables: ['query' => 'Hello'],
        );

        $result = $engine->run($this->organization->id, $request);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(AiRunStatus::Failed, $result->status);

        $run = AiRun::find($result->runId);
        $this->assertNotNull($run);
        $this->assertSame('budget_exceeded', $run->error_category?->value);
    }

    public function test_workflow_engine_fails_closed_when_kill_switch_is_active(): void
    {
        AiOrganizationSafetyControl::create([
            'organization_id' => $this->organization->id,
            'is_ai_globally_enabled' => false,
        ]);

        /** @var AiWorkflowEngine $engine */
        $engine = app(AiWorkflowEngine::class);

        $request = new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'kill_switch_test',
            inputVariables: ['query' => 'Hello'],
        );

        $this->expectException(AiKillSwitchException::class);
        $engine->run($this->organization->id, $request);
    }

    public function test_workflow_engine_fails_closed_when_capability_is_disabled_for_organization(): void
    {
        AiOrganizationSafetyControl::create([
            'organization_id' => $this->organization->id,
            'is_ai_globally_enabled' => true,
            'disabled_capabilities' => [AiCapability::ClientCompanion->value],
        ]);

        /** @var AiWorkflowEngine $engine */
        $engine = app(AiWorkflowEngine::class);

        $request = new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'capability_disabled_test',
            inputVariables: ['query' => 'Hello'],
        );

        $this->expectException(AiKillSwitchException::class);
        $engine->run($this->organization->id, $request);
    }

    public function test_workflow_engine_idempotent_duplicate_request_returns_existing_run_without_duplicate_provider_call(): void
    {
        DynamicWorkflowAgent::fake(['{"summary": "Idempotent response", "document_type": "epicrisis", "extracted_facts": []}']);

        $this->setupConfiguredModel(AiCapability::ClinicalDocumentExtraction);

        /** @var AiWorkflowEngine $engine */
        $engine = app(AiWorkflowEngine::class);

        $idempotencyKey = 'unique-request-key-12345';

        $request = new AiRunRequest(
            capability: AiCapability::ClinicalDocumentExtraction,
            workflowKey: 'idempotent_test',
            idempotencyKey: $idempotencyKey,
            inputVariables: ['document_text' => 'Текст'],
        );

        $result1 = $engine->run($this->organization->id, $request);
        $this->assertTrue($result1->isSuccess());

        // Repeat with exact same idempotencyKey
        $result2 = $engine->run($this->organization->id, $request);
        $this->assertTrue($result2->isSuccess());
        $this->assertSame($result1->runId, $result2->runId);

        // Verify only 1 AiRun was created in database
        $count = AiRun::where('organization_id', $this->organization->id)->where('idempotency_key', $idempotencyKey)->count();
        $this->assertSame(1, $count);
    }

    public function test_workflow_engine_rejects_unallowed_input_reference_type(): void
    {
        /** @var AiWorkflowEngine $engine */
        $engine = app(AiWorkflowEngine::class);

        $request = new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'invalid_ref_test',
            inputReferences: [new AiInputReference('survey_attempt', 999)],
        );

        $this->expectException(InvalidArgumentException::class);
        $engine->run($this->organization->id, $request);
    }
}
