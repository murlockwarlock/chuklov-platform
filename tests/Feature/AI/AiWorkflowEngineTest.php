<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\DispatchAsyncAiRun;
use App\Modules\AI\Application\Actions\ReclaimExpiredAiRuns;
use App\Modules\AI\Application\Actions\ReconcileExpiredAiRun;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Domain\Contracts\AiContextAssemblerInterface;
use App\Modules\AI\Domain\Contracts\AiToolRegistryInterface;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\BudgetReservationStatus;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Exceptions\AiKillSwitchException;
use App\Modules\AI\Domain\Exceptions\AiProviderUnavailableException;
use App\Modules\AI\Domain\Exceptions\AiRagRetrievalException;
use App\Modules\AI\Domain\Exceptions\AiToolExecutionFencedException;
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
use App\Modules\AI\Domain\Models\AiRunRagReference;
use App\Modules\AI\Domain\Models\AiRunToolCall;
use App\Modules\AI\Domain\Registry\AiCapabilityRegistry;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use App\Modules\AI\Domain\ValueObjects\AiContextPolicy;
use App\Modules\AI\Domain\ValueObjects\AiInputReference;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Domain\ValueObjects\AiRunExecutionContext;
use App\Modules\AI\Infrastructure\Context\AiContextAssembler;
use App\Modules\AI\Infrastructure\Engine\DynamicWorkflowAgent;
use App\Modules\AI\Infrastructure\Jobs\ProcessAiRunJob;
use App\Modules\AI\Infrastructure\Providers\AiProviderExecutionConfiguration;
use App\Modules\AI\Infrastructure\Tools\AiToolRegistry;
use App\Modules\AI\Infrastructure\Tools\SearchKnowledgeBaseSdkTool;
use App\Modules\AI\Infrastructure\Tools\SearchKnowledgeBaseTool;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Knowledge\Application\Data\RetrievalQuery;
use App\Modules\Knowledge\Application\Data\RetrievalResult;
use App\Modules\Knowledge\Domain\Contracts\KnowledgeRetriever;
use App\Modules\Knowledge\Domain\Models\KnowledgeChunk;
use App\Modules\Knowledge\Domain\Models\KnowledgeIngestionRun;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingExecutionSnapshot;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
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

        $this->createActivePrompt(AiCapability::ClientCompanion, 'default_client_companion');
        $this->createActivePrompt(AiCapability::ClinicalDocumentExtraction, 'default_clinical_document');
    }

    private function createActivePrompt(AiCapability $capability, string $key): void
    {
        $prompt = AiPrompt::create([
            'organization_id' => $this->organization->id,
            'key' => $key,
            'name' => $key,
            'capability' => $capability,
        ]);
        $version = AiPromptVersion::create([
            'organization_id' => $this->organization->id,
            'prompt_id' => $prompt->id,
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'Use the versioned test instructions.',
            'user_prompt_template' => '{{query}} {{document_text}}',
            'context_policy' => $capability === AiCapability::ClientCompanion ? ['include_rag' => true] : [],
            'allowed_tools' => $capability === AiCapability::ClientCompanion ? ['search_knowledge_base'] : [],
            'activated_at' => Carbon::now(),
        ]);
        $prompt->update(['active_version_id' => $version->id]);
    }

    /** @return array{source_id: int, revision_id: int, chunk_id: int} */
    private function createKnowledgeChunk(string $content, string $title = 'Test knowledge'): array
    {
        $source = KnowledgeSource::create([
            'organization_id' => $this->organization->id,
            'type' => 'authored_text',
            'title' => $title,
            'status' => 'active',
        ]);
        $revision = KnowledgeRevision::create([
            'organization_id' => $this->organization->id,
            'knowledge_source_id' => $source->id,
            'version' => 1,
            'status' => 'ready',
            'content' => $content,
            'mime_type' => 'text/plain',
            'size_bytes' => strlen($content),
            'content_checksum' => hash('sha256', $content),
            'ready_at' => Carbon::now(),
        ]);
        $source->update(['active_revision_id' => $revision->id]);
        $embeddingDimensions = (int) config('rag.embedding.dimensions');
        $embedding = array_fill(0, $embeddingDimensions, 0.0);
        $embedding[0] = 1.0;

        $ingestionRun = KnowledgeIngestionRun::create([
            'organization_id' => $this->organization->id,
            'knowledge_source_id' => $source->id,
            'knowledge_revision_id' => $revision->id,
            'configuration_key' => 'test_embedding',
            'status' => 'ready',
            'chunk_strategy' => 'fixed',
            'chunk_version' => 'v1',
            'chunk_target_characters' => 128,
            'chunk_maximum_characters' => 256,
            'chunk_overlap_characters' => 0,
            'embedding_provider' => 'test',
            'embedding_model' => 'test',
            'embedding_dimensions' => $embeddingDimensions,
            'embedding_configuration_version' => 'v1',
            'attempts' => 1,
            'completed_at' => Carbon::now(),
        ]);
        $chunk = KnowledgeChunk::create([
            'organization_id' => $this->organization->id,
            'knowledge_source_id' => $source->id,
            'knowledge_revision_id' => $revision->id,
            'knowledge_ingestion_run_id' => $ingestionRun->id,
            'chunk_index' => 0,
            'start_offset' => 0,
            'end_offset' => strlen($content),
            'source_reference' => 'test section',
            'content_checksum' => hash('sha256', $content),
            'content' => $content,
            'embedding' => $embedding,
        ]);

        return [
            'source_id' => $source->id,
            'revision_id' => $revision->id,
            'chunk_id' => $chunk->id,
        ];
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
            'health_status' => ProviderHealthStatus::Healthy,
            'credential_id' => $credential->id,
            'tested_credential_revision' => $credential->revision_id,
            'tested_configuration_digest' => AiProviderExecutionConfiguration::digest($providerName),
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
        $this->assertSame($prompt->id, $run->prompt_id);
        $this->assertSame($version->id, $run->prompt_version_id);
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

    public function test_provider_reported_usage_and_immutable_pricing_drive_persisted_provenance(): void
    {
        $model = $this->setupConfiguredModel(AiCapability::ClientCompanion);
        $release = $model->activeRelease;
        $this->assertNotNull($release);
        $pricing = new AiPricingSnapshot(
            currency: 'USD',
            inputCostPerMillionMinorUnits: 10000,
            outputCostPerMillionMinorUnits: 10000,
        );
        $release->update(['pricing_snapshot' => $pricing->toArray()]);

        DynamicWorkflowAgent::fake([
            new TextResponse(
                text: 'A short provider response',
                usage: new Usage(
                    promptTokens: 123,
                    completionTokens: 45,
                    cacheWriteInputTokens: 7,
                    cacheReadInputTokens: 8,
                    reasoningTokens: 9,
                ),
                meta: new Meta('openai', 'provider-reported-model'),
            ),
        ]);

        /** @var AiWorkflowEngine $engine */
        $engine = app(AiWorkflowEngine::class);
        $result = $engine->run($this->organization->id, new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'provider_usage_provenance_test',
            inputVariables: ['query' => 'short'],
        ));

        $this->assertTrue($result->isSuccess());
        $this->assertSame(123, $result->tokenUsage->promptTokens);
        $this->assertSame(45, $result->tokenUsage->completionTokens);
        $this->assertSame(176, $result->tokenUsage->totalTokens);
        $this->assertSame(7, $result->tokenUsage->cacheWriteInputTokens);
        $this->assertSame(8, $result->tokenUsage->cacheReadInputTokens);
        $this->assertSame(9, $result->tokenUsage->reasoningTokens);
        $this->assertSame('provider_reported', $result->tokenUsage->usageSource);

        $run = AiRun::query()->findOrFail($result->runId);
        $this->assertSame('openai', $run->actual_provider);
        $this->assertSame('provider-reported-model', $run->actual_model);
        $attempt = AiRunAttempt::query()->where('ai_run_id', $run->id)->firstOrFail();
        $expectedCost = $pricing->calculateCostMinorUnits(123, 45);
        $this->assertSame($expectedCost, $run->settled_estimated_cost_minor_units);
        $this->assertSame($expectedCost, $attempt->settled_estimated_cost_minor_units);
        $capability = AiCapabilityRegistry::get(AiCapability::ClientCompanion);
        $exposure = AiRuntimeLimits::worstCaseProviderExposure(
            maxInputTokens: $capability->maxInputTokens,
            maxOutputTokens: $capability->defaultMaxTokens,
            maxToolCalls: $capability->maxToolCalls,
            maxProviderSteps: $capability->maxProviderSteps,
            maxRagContextTokens: $capability->maxRagContextTokens,
        );
        $this->assertSame(
            $pricing->calculateCostMinorUnits($exposure['input_tokens'], $exposure['output_tokens']),
            $attempt->reserved_cost_minor_units,
        );
        $this->assertNull($run->provider_cost_minor_units);
        $this->assertNull($attempt->provider_cost_minor_units);
        $this->assertSame('provider_reported', $run->getTokenUsage()->usageSource);
    }

    public function test_multi_step_tool_loop_settles_each_actual_provider_request_fixed_cost(): void
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
        $this->app->instance(
            AiContextAssemblerInterface::class,
            new AiContextAssembler($retriever),
        );
        $this->app->instance(
            AiToolRegistryInterface::class,
            new AiToolRegistry([new SearchKnowledgeBaseTool($retriever)]),
        );

        $model = $this->setupConfiguredModel(AiCapability::ClientCompanion);
        $release = $model->activeRelease;
        $this->assertNotNull($release);
        $pricing = new AiPricingSnapshot(
            currency: 'USD',
            inputCostPerMillionMinorUnits: 0,
            outputCostPerMillionMinorUnits: 0,
            cacheReadInputCostPerMillionMinorUnits: 0,
            cacheWriteInputCostPerMillionMinorUnits: 0,
            reasoningCostPerMillionMinorUnits: 0,
            fixedRequestCostApplicable: true,
            fixedRequestCostMinorUnits: 11,
        );
        $release->update(['pricing_snapshot' => $pricing->toArray()]);

        DynamicWorkflowAgent::fake([
            new ToolCall('tool-call-1', 'SearchKnowledgeBaseSdkTool', ['query' => 'bounded loop']),
            'Final response after the tool loop',
        ]);

        /** @var AiWorkflowEngine $engine */
        $engine = app(AiWorkflowEngine::class);
        $result = $engine->run($this->organization->id, new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'fixed_request_tool_loop_test',
            inputVariables: ['query' => 'bounded loop'],
        ));

        $this->assertTrue($result->isSuccess());
        $run = AiRun::query()->findOrFail($result->runId);
        $attempt = AiRunAttempt::query()->where('ai_run_id', $run->id)->firstOrFail();
        $this->assertSame(2, $result->tokenUsage->providerRequests);
        $this->assertSame(2, $run->getTokenUsage()->providerRequests);
        $this->assertSame(2, $attempt->getTokenUsage()->providerRequests);
        $this->assertSame(22, $attempt->settled_estimated_cost_minor_units);
        $this->assertNotSame(11, $attempt->settled_estimated_cost_minor_units);
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

    public function test_protected_medical_context_requires_an_explicit_actor(): void
    {
        $client = Client::factory()->forOrganization($this->organization)->create();
        $assembler = new AiContextAssembler(knowledgeRetriever: app(KnowledgeRetriever::class));

        $this->expectException(InvalidArgumentException::class);
        $assembler->assemble(
            organizationId: $this->organization->id,
            policy: new AiContextPolicy(includeMedicalSummary: true),
            inputVariables: [],
            inputReferences: [new AiInputReference('client', $client->id)],
            actor: null,
        );
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

    public function test_sdk_knowledge_tool_filters_results_below_the_context_similarity_threshold(): void
    {
        $high = $this->createKnowledgeChunk('Keep this result.', 'High match');
        $low = $this->createKnowledgeChunk('Filter this result.', 'Low match');
        $fakeRetriever = new class($high, $low) implements KnowledgeRetriever
        {
            public function __construct(private array $high, private array $low) {}

            public function retrieve(User $actor, RetrievalQuery $query): array
            {
                return $this->retrieveForOrganization(1, $query);
            }

            public function retrieveForOrganization(int|string $organizationId, RetrievalQuery $query): array
            {
                return [
                    new RetrievalResult($this->high['chunk_id'], $this->high['source_id'], 'High match', 'authored_text', $this->high['revision_id'], 1, 0, 'Keep this result.', 0.91, null, 0, 16, 1, 'test_embedding'),
                    new RetrievalResult($this->low['chunk_id'], $this->low['source_id'], 'Low match', 'authored_text', $this->low['revision_id'], 1, 1, 'Filter this result.', 0.42, null, 16, 32, 1, 'test_embedding'),
                ];
            }
        };
        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'tool_similarity_test',
            'status' => AiRunStatus::Running,
            'worker_lease_token' => (string) Str::uuid(),
            'worker_lease_expires_at' => Carbon::now()->addMinutes(5),
            'execution_deadline_at' => Carbon::now()->addMinutes(10),
            'input_references' => [],
            'context_provenance' => ['retrieval_embedding' => EmbeddingExecutionSnapshot::active()->toArray()],
            'token_usage' => [],
        ]);
        $tool = new SearchKnowledgeBaseSdkTool(
            executionContext: new AiRunExecutionContext(
                organizationId: $this->organization->id,
                aiRunId: $run->id,
                workerLeaseToken: $run->worker_lease_token,
            ),
            domainTool: new SearchKnowledgeBaseTool(knowledgeRetriever: $fakeRetriever),
            maxToolCalls: 2,
            minimumSimilarity: 0.65,
            policyMaxResults: 2,
        );

        $response = (string) $tool->handle(new Request(['query' => 'threshold']));

        $this->assertStringContainsString('Keep this result.', $response);
        $this->assertStringNotContainsString('Filter this result.', $response);
        $this->assertSame(1, AiRunToolCall::query()->where('ai_run_id', $run->id)->firstOrFail()->call_index);
        $this->assertSame(1, AiRunRagReference::query()->where('ai_run_id', $run->id)->count());
    }

    public function test_sdk_knowledge_tool_fails_closed_when_model_requests_source_outside_policy_scope(): void
    {
        $retriever = new class implements KnowledgeRetriever
        {
            public int $calls = 0;

            public function retrieve(User $actor, RetrievalQuery $query): array
            {
                return $this->retrieveForOrganization(1, $query);
            }

            public function retrieveForOrganization(int|string $organizationId, RetrievalQuery $query): array
            {
                $this->calls++;

                return [];
            }
        };
        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'tool_scope_rejection_test',
            'status' => AiRunStatus::Running,
            'worker_lease_token' => (string) Str::uuid(),
            'worker_lease_expires_at' => Carbon::now()->addMinutes(5),
            'execution_deadline_at' => Carbon::now()->addMinutes(10),
            'input_references' => [],
            'context_provenance' => ['retrieval_embedding' => EmbeddingExecutionSnapshot::active()->toArray()],
            'token_usage' => [],
        ]);
        $tool = new SearchKnowledgeBaseSdkTool(
            executionContext: new AiRunExecutionContext($this->organization->id, $run->id, $run->worker_lease_token),
            domainTool: new SearchKnowledgeBaseTool(knowledgeRetriever: $retriever),
            maxToolCalls: 2,
            allowedKnowledgeSourceIds: [101],
            policyMaxResults: 3,
        );

        $response = (string) $tool->handle(new Request([
            'query' => 'outside source',
            'knowledge_source_ids' => [202],
            'max_results' => 10,
        ]));

        $this->assertSame('No relevant knowledge base records found.', $response);
        $this->assertSame(0, $retriever->calls);
        $this->assertSame(1, AiRunToolCall::query()->where('ai_run_id', $run->id)->count());
        $this->assertSame(0, AiRunRagReference::query()->where('ai_run_id', $run->id)->count());
    }

    public function test_sdk_knowledge_tool_intersects_source_scope_and_caps_model_result_count(): void
    {
        $allowed = $this->createKnowledgeChunk('Allowed policy content.', 'Allowed policy source');
        $retriever = new class($allowed) implements KnowledgeRetriever
        {
            public ?RetrievalQuery $lastQuery = null;

            public function __construct(private readonly array $allowed) {}

            public function retrieve(User $actor, RetrievalQuery $query): array
            {
                return $this->retrieveForOrganization(1, $query);
            }

            public function retrieveForOrganization(int|string $organizationId, RetrievalQuery $query): array
            {
                $this->lastQuery = $query;
                $results = [];
                for ($index = 0; $index < 5; $index++) {
                    $results[] = new RetrievalResult(
                        chunkId: $this->allowed['chunk_id'],
                        sourceId: $this->allowed['source_id'],
                        sourceTitle: 'Allowed policy source',
                        sourceType: 'authored_text',
                        revisionId: $this->allowed['revision_id'],
                        revisionVersion: 1,
                        chunkIndex: $index,
                        content: "Allowed result {$index}.",
                        similarity: 0.95,
                        sourceReference: null,
                        startOffset: $index,
                        endOffset: $index + 1,
                        ingestionRunId: 1,
                        embeddingConfigurationKey: 'test_embedding',
                    );
                }

                return $results;
            }
        };
        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'tool_scope_limit_test',
            'status' => AiRunStatus::Running,
            'worker_lease_token' => (string) Str::uuid(),
            'worker_lease_expires_at' => Carbon::now()->addMinutes(5),
            'execution_deadline_at' => Carbon::now()->addMinutes(10),
            'input_references' => [],
            'context_provenance' => ['retrieval_embedding' => EmbeddingExecutionSnapshot::active()->toArray()],
            'token_usage' => [],
        ]);
        $tool = new SearchKnowledgeBaseSdkTool(
            executionContext: new AiRunExecutionContext($this->organization->id, $run->id, $run->worker_lease_token),
            domainTool: new SearchKnowledgeBaseTool(knowledgeRetriever: $retriever),
            maxToolCalls: 2,
            allowedKnowledgeSourceIds: [$allowed['source_id']],
            policyMaxResults: 3,
        );

        $response = json_decode((string) $tool->handle(new Request([
            'query' => 'bounded source',
            'knowledge_source_ids' => [$allowed['source_id'], 999999],
            'max_results' => 10,
        ])), true);

        $this->assertIsArray($response);
        $this->assertSame(3, $response['count']);
        $this->assertCount(3, $response['results']);
        $this->assertNotNull($retriever->lastQuery);
        $this->assertSame(3, $retriever->lastQuery->topK);
        $this->assertSame([$allowed['source_id']], $retriever->lastQuery->sourceIds);
        $this->assertSame(3, AiRunRagReference::query()->where('ai_run_id', $run->id)->count());
    }

    public function test_incompatible_knowledge_embedding_is_a_typed_configuration_failure(): void
    {
        $retriever = new class implements KnowledgeRetriever
        {
            public function retrieve(User $actor, RetrievalQuery $query): array
            {
                return [];
            }

            public function retrieveForOrganization(int|string $organizationId, RetrievalQuery $query): array
            {
                throw new \RuntimeException('Active embedding configuration is incompatible.');
            }
        };

        try {
            (new SearchKnowledgeBaseTool(knowledgeRetriever: $retriever))->execute(
                $this->organization->id,
                ['query' => 'configuration'],
            );
            $this->fail('Expected an incompatible embedding configuration failure.');
        } catch (AiRagRetrievalException $exception) {
            $this->assertSame('configuration', $exception->reason);
            $this->assertStringNotContainsString('incompatible', strtolower($exception->getMessage()));
        }
    }

    public function test_real_sdk_tool_execution_creates_durable_ai_run_tool_call(): void
    {
        $reference = $this->createKnowledgeChunk('Shoulder rehabilitation protocols.', 'Rehab Guidelines');
        $fakeRetriever = new class($reference) implements KnowledgeRetriever
        {
            public function __construct(private array $reference) {}

            public function retrieve(User $actor, RetrievalQuery $query): array
            {
                return $this->retrieveForOrganization(1, $query);
            }

            public function retrieveForOrganization(int|string $organizationId, RetrievalQuery $query): array
            {
                return [
                    new RetrievalResult(
                        chunkId: $this->reference['chunk_id'],
                        sourceId: $this->reference['source_id'],
                        sourceTitle: 'Rehab Guidelines',
                        sourceType: 'authored_text',
                        revisionId: $this->reference['revision_id'],
                        revisionVersion: 1,
                        chunkIndex: 0,
                        content: 'Shoulder rehabilitation protocols.',
                        similarity: 0.95,
                        sourceReference: 'Section 1',
                        startOffset: 0,
                        endOffset: 34,
                        ingestionRunId: 1,
                        embeddingConfigurationKey: 'test_embedding',
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
            'worker_lease_token' => (string) Str::uuid(),
            'worker_lease_expires_at' => Carbon::now()->addMinutes(5),
            'execution_deadline_at' => Carbon::now()->addMinutes(10),
            'input_references' => [],
            'context_provenance' => ['retrieval_embedding' => EmbeddingExecutionSnapshot::active()->toArray()],
            'token_usage' => [],
        ]);

        $sdkTool = new SearchKnowledgeBaseSdkTool(
            executionContext: new AiRunExecutionContext(
                organizationId: $this->organization->id,
                aiRunId: $run->id,
                workerLeaseToken: $run->worker_lease_token,
            ),
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

        $ragReference = AiRunRagReference::query()->where('ai_run_id', $run->id)->firstOrFail();
        $this->assertSame($call->id, $ragReference->ai_run_tool_call_id);
        $this->assertSame('tool', $ragReference->retrieval_type);
        $this->assertSame($reference['source_id'], $ragReference->knowledge_source_id);
        $this->assertSame($reference['revision_id'], $ragReference->knowledge_revision_id);
        $this->assertSame($reference['chunk_id'], $ragReference->knowledge_chunk_id);
        $this->assertArrayNotHasKey('content', $ragReference->getAttributes());

        $this->expectException(QueryException::class);
        KnowledgeChunk::query()->whereKey($reference['chunk_id'])->delete();
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
            'worker_lease_token' => (string) Str::uuid(),
            'worker_lease_expires_at' => Carbon::now()->addMinutes(5),
            'execution_deadline_at' => Carbon::now()->addMinutes(10),
            'input_references' => [],
            'context_provenance' => ['retrieval_embedding' => EmbeddingExecutionSnapshot::active()->toArray()],
            'token_usage' => [],
        ]);

        $sdkTool = new SearchKnowledgeBaseSdkTool(
            executionContext: new AiRunExecutionContext(
                organizationId: $this->organization->id,
                aiRunId: $run->id,
                workerLeaseToken: $run->worker_lease_token,
            ),
            domainTool: $domainTool,
            maxToolCalls: 1, // Limit = 1
        );

        // Call 1: Succeeded
        $sdkTool->handle(new Request(['query' => 'q1']));

        // Call 2: Must throw AiToolLimitExceededException
        $this->expectException(AiToolLimitExceededException::class);
        $sdkTool->handle(new Request(['query' => 'q2']));

        $this->assertSame(1, AiRunToolCall::where('ai_run_id', $run->id)->count());
    }

    public function test_reclaimed_worker_continues_durable_tool_index_and_cannot_bypass_limit(): void
    {
        $emptyRetriever = new class implements KnowledgeRetriever
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
        $domainTool = new SearchKnowledgeBaseTool(knowledgeRetriever: $emptyRetriever);
        $tokenA = (string) Str::uuid();
        $tokenB = (string) Str::uuid();
        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'durable_tool_index_test',
            'status' => AiRunStatus::Running,
            'worker_lease_token' => $tokenA,
            'worker_lease_expires_at' => Carbon::now()->addMinutes(5),
            'execution_deadline_at' => Carbon::now()->addMinutes(10),
            'input_references' => [],
            'context_provenance' => ['retrieval_embedding' => EmbeddingExecutionSnapshot::active()->toArray()],
            'token_usage' => [],
        ]);

        $firstWorker = new SearchKnowledgeBaseSdkTool(
            executionContext: new AiRunExecutionContext($this->organization->id, $run->id, $tokenA),
            domainTool: $domainTool,
            maxToolCalls: 2,
        );
        $firstWorker->handle(new Request(['query' => 'first']));

        $run->update(['worker_lease_token' => $tokenB]);
        $secondWorker = new SearchKnowledgeBaseSdkTool(
            executionContext: new AiRunExecutionContext($this->organization->id, $run->id, $tokenB),
            domainTool: $domainTool,
            maxToolCalls: 2,
        );
        $secondWorker->handle(new Request(['query' => 'second']));

        $calls = AiRunToolCall::query()->where('ai_run_id', $run->id)->orderBy('call_index')->get();
        $this->assertSame([1, 2], $calls->pluck('call_index')->all());

        try {
            $secondWorker->handle(new Request(['query' => 'third']));
            $this->fail('Expected the durable tool-call limit to be enforced after reclaim.');
        } catch (AiToolLimitExceededException) {
            $this->assertTrue(true);
        }

        $this->assertSame(2, AiRunToolCall::query()->where('ai_run_id', $run->id)->count());
    }

    public function test_stale_worker_cannot_finalize_tool_provenance_after_lease_loss(): void
    {
        $tokenA = (string) Str::uuid();
        $tokenB = (string) Str::uuid();
        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'stale_tool_provenance_test',
            'status' => AiRunStatus::Running,
            'worker_lease_token' => $tokenA,
            'worker_lease_expires_at' => Carbon::now()->addMinutes(5),
            'execution_deadline_at' => Carbon::now()->addMinutes(10),
            'input_references' => [],
            'context_provenance' => ['retrieval_embedding' => EmbeddingExecutionSnapshot::active()->toArray()],
            'token_usage' => [],
        ]);
        $retriever = new class($run->id, $tokenB) implements KnowledgeRetriever
        {
            public function __construct(private readonly int $runId, private readonly string $newToken) {}

            public function retrieve(User $actor, RetrievalQuery $query): array
            {
                return $this->retrieveForOrganization(1, $query);
            }

            public function retrieveForOrganization(int|string $organizationId, RetrievalQuery $query): array
            {
                AiRun::query()->whereKey($this->runId)->update(['worker_lease_token' => $this->newToken]);

                return [];
            }
        };

        $tool = new SearchKnowledgeBaseSdkTool(
            executionContext: new AiRunExecutionContext($this->organization->id, $run->id, $tokenA),
            domainTool: new SearchKnowledgeBaseTool(knowledgeRetriever: $retriever),
            maxToolCalls: 2,
        );

        try {
            $tool->handle(new Request(['query' => 'lease loss']));
            $this->fail('Expected stale tool execution to be fenced.');
        } catch (AiToolExecutionFencedException) {
            $this->assertTrue(true);
        }

        $call = AiRunToolCall::query()->where('ai_run_id', $run->id)->first();
        $this->assertNotNull($call);
        $this->assertSame('running', $call->execution_status);
        $this->assertNull($call->error_sanitized);
    }

    public function test_provider_worker_losing_lease_during_call_cannot_write_attempt_outcome_and_reclaimer_charges_once(): void
    {
        $this->setupConfiguredModel(AiCapability::ClientCompanion);

        $tokenA = (string) Str::uuid();
        $tokenB = (string) Str::uuid();
        $prompt = AiPrompt::query()
            ->where('organization_id', $this->organization->id)
            ->where('capability', AiCapability::ClientCompanion)
            ->firstOrFail();
        $promptVersion = AiPromptVersion::query()
            ->where('organization_id', $this->organization->id)
            ->whereKey($prompt->active_version_id)
            ->firstOrFail();
        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'stale_provider_attempt_test',
            'status' => AiRunStatus::Running,
            'prompt_id' => $prompt->id,
            'prompt_version_id' => $promptVersion->id,
            'worker_lease_token' => $tokenA,
            'worker_lease_expires_at' => Carbon::now()->addMinutes(5),
            'execution_deadline_at' => Carbon::now()->addMinutes(10),
            'input_references' => [],
            'context_provenance' => ['retrieval_embedding' => EmbeddingExecutionSnapshot::active()->toArray()],
            'token_usage' => [],
            'started_at' => Carbon::now(),
        ]);
        AiRunPayload::create([
            'organization_id' => $this->organization->id,
            'ai_run_id' => $run->id,
            'encryption_key_version' => 1,
            'encrypted_system_prompt' => app(MedicalEncryptorInterface::class)->encryptField($this->organization->id, 'Use the versioned test instructions.', 1),
            'encrypted_user_prompt' => app(MedicalEncryptorInterface::class)->encryptField($this->organization->id, 'provider call held open', 1),
        ]);

        DynamicWorkflowAgent::fake(function () use ($run, $tokenB): string {
            AiRun::query()
                ->where('organization_id', $run->organization_id)
                ->whereKey($run->id)
                ->update([
                    'worker_lease_token' => $tokenB,
                    'worker_lease_expires_at' => Carbon::now()->subSecond(),
                ]);

            return 'Provider response returned after lease transfer.';
        });

        $result = app(AiWorkflowEngine::class)->executeRun(
            organizationId: $this->organization->id,
            runId: $run->id,
            workerLeaseToken: $tokenA,
        );

        $this->assertFalse($result->isSuccess());
        $run->refresh();
        $this->assertSame(AiRunStatus::Running, $run->status);
        $this->assertSame($tokenB, $run->worker_lease_token);

        $attempt = AiRunAttempt::query()->where('ai_run_id', $run->id)->firstOrFail();
        $this->assertSame('running', $attempt->status);
        $this->assertSame(BudgetReservationStatus::Reserved, $attempt->budget_reservation_status);
        $this->assertNull($attempt->retry_or_failover_reason);
        $this->assertNull($attempt->error_message_sanitized);
        $this->assertNull($attempt->finished_at);
        $reservedCost = $attempt->reserved_cost_minor_units;

        Queue::fake();
        $reclaimResult = app(ReclaimExpiredAiRuns::class)->handle();

        $this->assertSame(['reclaimed' => 1, 'dispatched' => 1], $reclaimResult);
        Queue::assertPushed(ProcessAiRunJob::class, 1);
        $attempt->refresh();
        $this->assertSame('failed', $attempt->status);
        $this->assertSame('Expired worker lease was reconciled before reassignment.', $attempt->retry_or_failover_reason);
        $this->assertSame(BudgetReservationStatus::ConservativelyCharged, $attempt->budget_reservation_status);
        $budget = AiOrganizationDailyBudget::query()
            ->where('organization_id', $this->organization->id)
            ->whereDate('usage_date', Carbon::now()->toDateString())
            ->firstOrFail();
        $spentAfterFirstReconcile = $budget->spent_minor_units;
        $this->assertSame($reservedCost, $spentAfterFirstReconcile);
        $this->assertSame(0, $budget->reserved_minor_units);

        app(ReconcileExpiredAiRun::class)->handle($run->refresh(), 'Repeated reconciliation must be idempotent.');
        $this->assertSame($spentAfterFirstReconcile, $budget->refresh()->spent_minor_units);
    }

    public function test_failed_tool_persists_safe_error_in_error_sanitized_column(): void
    {
        $token = (string) Str::uuid();
        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'failed_tool_error_test',
            'status' => AiRunStatus::Running,
            'worker_lease_token' => $token,
            'worker_lease_expires_at' => Carbon::now()->addMinutes(5),
            'execution_deadline_at' => Carbon::now()->addMinutes(10),
            'input_references' => [],
            'context_provenance' => ['retrieval_embedding' => EmbeddingExecutionSnapshot::active()->toArray()],
            'token_usage' => [],
        ]);
        $retriever = new class implements KnowledgeRetriever
        {
            public function retrieve(User $actor, RetrievalQuery $query): array
            {
                throw new \RuntimeException('raw vector database password leaked');
            }

            public function retrieveForOrganization(int|string $organizationId, RetrievalQuery $query): array
            {
                throw new \RuntimeException('raw vector database password leaked');
            }
        };
        $tool = new SearchKnowledgeBaseSdkTool(
            executionContext: new AiRunExecutionContext($this->organization->id, $run->id, $token),
            domainTool: new SearchKnowledgeBaseTool(knowledgeRetriever: $retriever),
            maxToolCalls: 2,
        );

        try {
            $tool->handle(new Request(['query' => 'failure']));
            $this->fail('Expected the retrieval failure to be raised.');
        } catch (AiRagRetrievalException) {
            $this->assertTrue(true);
        }

        $call = AiRunToolCall::query()->where('ai_run_id', $run->id)->first();
        $this->assertNotNull($call);
        $this->assertSame('failed', $call->execution_status);
        $this->assertSame('Knowledge retrieval failed safely.', $call->error_sanitized);
        $this->assertStringNotContainsString('password', (string) $call->error_sanitized);
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

    public function test_workflow_rejects_prompt_input_that_exceeds_the_bounded_input_limit_before_persistence(): void
    {
        $this->setupConfiguredModel(AiCapability::ClinicalDocumentExtraction);

        /** @var AiWorkflowEngine $engine */
        $engine = app(AiWorkflowEngine::class);

        try {
            $engine->run($this->organization->id, new AiRunRequest(
                capability: AiCapability::ClinicalDocumentExtraction,
                workflowKey: 'oversized_prompt_test',
                inputVariables: ['query' => str_repeat('x', 40000)],
            ));
            $this->fail('Expected an oversized rendered prompt to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('bounded input limit', $e->getMessage());
        }

        $run = AiRun::query()->where('organization_id', $this->organization->id)->sole();
        $this->assertSame(AiRunStatus::Failed, $run->status);
    }

    public function test_workflow_rejects_rag_context_that_exceeds_the_bounded_context_limit(): void
    {
        $this->setupConfiguredModel(AiCapability::ClientCompanion);
        $largeRetriever = new class implements KnowledgeRetriever
        {
            public function retrieve(User $actor, RetrievalQuery $query): array
            {
                return $this->retrieveForOrganization(1, $query);
            }

            public function retrieveForOrganization(int|string $organizationId, RetrievalQuery $query): array
            {
                return [new RetrievalResult(
                    chunkId: 900,
                    sourceId: 900,
                    sourceTitle: 'Oversized context',
                    sourceType: 'authored_text',
                    revisionId: 900,
                    revisionVersion: 1,
                    chunkIndex: 0,
                    content: str_repeat('large context ', 20000),
                    similarity: 0.99,
                    sourceReference: null,
                    startOffset: 0,
                    endOffset: 20000,
                    ingestionRunId: 900,
                    embeddingConfigurationKey: 'bounded-test',
                )];
            }
        };
        app()->instance(KnowledgeRetriever::class, $largeRetriever);

        /** @var AiWorkflowEngine $engine */
        $engine = app(AiWorkflowEngine::class);

        try {
            $engine->run($this->organization->id, new AiRunRequest(
                capability: AiCapability::ClientCompanion,
                workflowKey: 'oversized_rag_test',
                inputVariables: ['query' => 'bounded context'],
            ));
            $this->fail('Expected oversized RAG context to be rejected.');
        } catch (AiRagRetrievalException $e) {
            $this->assertSame('context_limit', $e->reason);
        }

        $run = AiRun::query()->where('organization_id', $this->organization->id)->sole();
        $this->assertSame(AiRunStatus::Failed, $run->status);
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

    public function test_sync_and_async_execution_fail_closed_without_versioned_prompt_or_with_wrong_org_prompt(): void
    {
        $providerCalls = 0;
        DynamicWorkflowAgent::fake(function () use (&$providerCalls): string {
            $providerCalls++;

            return 'Provider must not be called.';
        });

        $organizationWithoutPrompt = Organization::factory()->create();
        $userWithoutPrompt = User::factory()->forOrganization($organizationWithoutPrompt, OrganizationRole::Administrator)->create();

        try {
            app(AiWorkflowEngine::class)->run($organizationWithoutPrompt->id, new AiRunRequest(
                capability: AiCapability::ClientCompanion,
                workflowKey: 'missing_prompt_sync_test',
                inputVariables: ['query' => 'must fail closed'],
            ));
            $this->fail('Synchronous execution without a prompt version must fail closed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('active prompt version', $exception->getMessage());
        }

        app(OrganizationContext::class)->set($organizationWithoutPrompt);
        Queue::fake();
        try {
            app(DispatchAsyncAiRun::class)->handle($userWithoutPrompt, new AiRunRequest(
                capability: AiCapability::ClientCompanion,
                workflowKey: 'missing_prompt_async_test',
                inputVariables: ['query' => 'must fail closed'],
            ));
            $this->fail('Asynchronous execution without a prompt version must fail closed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('active prompt version', $exception->getMessage());
        }

        Queue::assertNothingPushed();
        $this->assertSame(0, AiRun::query()->count());

        $wrongOrganization = Organization::factory()->create();
        $wrongPrompt = AiPrompt::create([
            'organization_id' => $wrongOrganization->id,
            'key' => 'wrong_org_prompt',
            'name' => 'Wrong organization prompt',
            'capability' => AiCapability::ClientCompanion,
        ]);
        $wrongVersion = AiPromptVersion::create([
            'organization_id' => $wrongOrganization->id,
            'prompt_id' => $wrongPrompt->id,
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'Wrong organization instructions.',
            'user_prompt_template' => '{{query}}',
            'activated_at' => Carbon::now(),
        ]);
        $wrongPrompt->update(['active_version_id' => $wrongVersion->id]);

        app(OrganizationContext::class)->set($this->organization);
        try {
            app(AiWorkflowEngine::class)->run($this->organization->id, new AiRunRequest(
                capability: AiCapability::ClientCompanion,
                workflowKey: 'wrong_prompt_org_test',
                promptVersionId: $wrongVersion->id,
                inputVariables: ['query' => 'must fail closed'],
            ));
            $this->fail('A prompt version from another organization must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('active prompt version', $exception->getMessage());
        }

        $this->assertSame(0, $providerCalls);
        $this->assertSame(0, AiRun::query()->count());
    }
}
