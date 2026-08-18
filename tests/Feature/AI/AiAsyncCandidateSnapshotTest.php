<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\DispatchAsyncAiRun;
use App\Modules\AI\Application\Actions\ReconcileExpiredAiRun;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiErrorCategory;
use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\BudgetReservationStatus;
use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunAttempt;
use App\Modules\AI\Domain\ValueObjects\AiInputReference;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Infrastructure\Engine\DynamicWorkflowAgent;
use App\Modules\AI\Infrastructure\Jobs\ProcessAiRunJob;
use App\Modules\AI\Infrastructure\Providers\AiProviderExecutionConfiguration;
use App\Modules\Attachments\Domain\Enums\AttachmentScanStatus;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\ReplaceOrganizationCredential;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Events\PromptingAgent;
use Tests\TestCase;

final class AiAsyncCandidateSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private AiPromptVersion $promptVersion;

    private AiPromptVersion $clinicalPromptVersion;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');

        $this->organization = Organization::create([
            'name' => 'Snapshot Clinic',
            'slug' => 'snapshot-clinic',
        ]);
        $this->admin = User::factory()->forOrganization($this->organization, OrganizationRole::Administrator)->create();
        app(OrganizationContext::class)->set($this->organization);

        $prompt = AiPrompt::create([
            'organization_id' => $this->organization->id,
            'key' => 'snapshot_prompt',
            'name' => 'Snapshot prompt',
            'capability' => AiCapability::ClientCompanion,
        ]);
        $this->promptVersion = AiPromptVersion::create([
            'organization_id' => $this->organization->id,
            'prompt_id' => $prompt->id,
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'Answer safely.',
            'user_prompt_template' => '{{query}}',
            'context_policy' => ['include_rag' => false],
            'allowed_tools' => [],
            'activated_at' => now(),
        ]);
        $prompt->update(['active_version_id' => $this->promptVersion->id]);

        $clinicalPrompt = AiPrompt::create([
            'organization_id' => $this->organization->id,
            'key' => 'snapshot_clinical_prompt',
            'name' => 'Snapshot clinical prompt',
            'capability' => AiCapability::ClinicalDocumentExtraction,
        ]);
        $this->clinicalPromptVersion = AiPromptVersion::create([
            'organization_id' => $this->organization->id,
            'prompt_id' => $clinicalPrompt->id,
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'Extract safely.',
            'user_prompt_template' => '{{document_text}}',
            'context_policy' => ['include_rag' => false],
            'allowed_tools' => [],
            'activated_at' => now(),
        ]);
        $clinicalPrompt->update(['active_version_id' => $this->clinicalPromptVersion->id]);
    }

    public function test_async_worker_uses_release_accepted_before_activation_change(): void
    {
        [$modelConfig, $releaseOne] = $this->candidate('openai', 'OpenAI A', 'gpt-4o-mini', 1);
        Queue::fake();

        $run = app(DispatchAsyncAiRun::class)->handle($this->admin, new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'snapshot-release',
            promptVersionId: $this->promptVersion->id,
            inputVariables: ['query' => 'queued question'],
        ));

        $releaseTwo = AiModelRelease::create([
            'organization_id' => $this->organization->id,
            'model_config_id' => $modelConfig->id,
            'release_number' => 2,
            'status' => 'active',
            'provider_name' => 'openai',
            'model_name' => 'gpt-4o',
            'capabilities' => [AiCapability::ClientCompanion->value],
            'pricing_snapshot' => $releaseOne->pricing_snapshot,
            'activated_at' => now(),
        ]);
        $releaseOne->update(['status' => 'retired']);
        $modelConfig->update(['active_release_id' => $releaseTwo->id]);

        DynamicWorkflowAgent::fake(['snapshot response']);
        $this->runQueuedJob($run);

        $attempt = AiRunAttempt::query()->where('ai_run_id', $run->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame($releaseOne->id, $attempt->model_release_id);
        $this->assertSame('gpt-4o-mini', $attempt->model);
    }

    public function test_async_worker_uses_only_the_accepted_failover_order(): void
    {
        [, $firstRelease] = $this->candidate('openai', 'OpenAI first', 'gpt-4o-mini', 1);
        [, $secondRelease] = $this->candidate('anthropic', 'Anthropic second', 'claude-3.5-sonnet', 2);
        Queue::fake();

        $run = app(DispatchAsyncAiRun::class)->handle($this->admin, new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'snapshot-order',
            promptVersionId: $this->promptVersion->id,
            inputVariables: ['query' => 'queued question'],
        ));

        $snapshot = $run->fresh()->execution_candidate_snapshot;
        $this->assertSame($firstRelease->id, $snapshot[0]['model_release_id']);
        $this->assertSame($secondRelease->id, $snapshot[1]['model_release_id']);
        $this->assertSame([AiCapability::ClientCompanion->value], $snapshot[0]['capabilities']);

        [, $newRelease] = $this->candidate('gemini', 'Gemini new', 'gemini-2.0-flash', 0);
        AiModelConfiguration::query()->whereKey($firstRelease->model_config_id)->update(['failover_priority' => 99]);

        DynamicWorkflowAgent::fake(['snapshot response']);
        $this->runQueuedJob($run);

        $attempts = AiRunAttempt::query()->where('ai_run_id', $run->id)->orderBy('attempt_number')->get();
        $this->assertCount(1, $attempts);
        $this->assertSame($firstRelease->id, $attempts->first()->model_release_id);
        $this->assertNotSame($newRelease->id, $attempts->first()->model_release_id);
    }

    public function test_async_attachment_snapshot_keeps_later_modality_candidate_without_post_dispatch_discovery(): void
    {
        [, $firstRelease] = $this->candidateWithCapabilities(
            providerName: 'openai',
            credentialName: 'OpenAI text first',
            modelName: 'text-only-1',
            priority: 1,
            capability: AiCapability::ClinicalDocumentExtraction,
        );
        $this->candidateWithCapabilities(
            providerName: 'groq',
            credentialName: 'Groq text second',
            modelName: 'text-only-2',
            priority: 2,
            capability: AiCapability::ClinicalDocumentExtraction,
        );
        $this->candidateWithCapabilities(
            providerName: 'deepseek',
            credentialName: 'DeepSeek text third',
            modelName: 'text-only-3',
            priority: 3,
            capability: AiCapability::ClinicalDocumentExtraction,
        );
        [, $documentRelease] = $this->candidateWithCapabilities(
            providerName: 'anthropic',
            credentialName: 'Anthropic document fourth',
            modelName: 'document-capable-4',
            priority: 4,
            capability: AiCapability::ClinicalDocumentExtraction,
            modalities: [AiModelModality::DocumentInput],
        );
        $attachment = $this->createMedicalReportAttachment();
        Queue::fake();

        $run = app(DispatchAsyncAiRun::class)->handle($this->admin, new AiRunRequest(
            capability: AiCapability::ClinicalDocumentExtraction,
            workflowKey: 'snapshot-document-modality',
            promptVersionId: $this->clinicalPromptVersion->id,
            clientId: $attachment->client_id,
            inputVariables: ['document_text' => 'queued document'],
            inputReferences: [new AiInputReference('medical_attachment', $attachment->id)],
        ));

        $snapshot = $run->fresh()->execution_candidate_snapshot;
        $this->assertCount(4, $snapshot);
        $this->assertSame($firstRelease->id, $snapshot[0]['model_release_id']);
        $this->assertSame($documentRelease->id, $snapshot[3]['model_release_id']);
        $this->assertContains(AiModelModality::DocumentInput->value, $snapshot[3]['capabilities']);

        [, $newRelease] = $this->candidateWithCapabilities(
            providerName: 'gemini',
            credentialName: 'Gemini post dispatch',
            modelName: 'document-capable-created-later',
            priority: 5,
            capability: AiCapability::ClinicalDocumentExtraction,
            modalities: [AiModelModality::DocumentInput],
        );

        DynamicWorkflowAgent::fake(['snapshot document response']);
        $this->runQueuedJob($run);

        $attempts = AiRunAttempt::query()
            ->where('ai_run_id', $run->id)
            ->orderBy('attempt_number')
            ->get();
        $this->assertCount(1, $attempts);
        $this->assertSame($documentRelease->id, $attempts->first()->model_release_id);
        $this->assertNotSame($newRelease->id, $attempts->first()->model_release_id);
        $this->assertLessThanOrEqual(3, $attempts->count());
    }

    public function test_async_worker_fails_closed_after_snapshotted_credential_rotation(): void
    {
        [$modelConfig, $release] = $this->candidate('openai', 'OpenAI rotating', 'gpt-4o-mini', 1);
        Queue::fake();

        $run = app(DispatchAsyncAiRun::class)->handle($this->admin, new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'snapshot-credential',
            promptVersionId: $this->promptVersion->id,
            inputVariables: ['query' => 'queued question'],
        ));
        $credential = OrganizationCredential::query()
            ->where('organization_id', $this->organization->id)
            ->where('credential_name', 'OpenAI rotating')
            ->firstOrFail();
        $oldRevision = $credential->revision_id;

        app(ReplaceOrganizationCredential::class)->handle(
            actor: $this->admin,
            provider: 'openai',
            credentialName: 'OpenAI rotating',
            credentials: ['api_key' => 'rotated-key'],
        );

        DynamicWorkflowAgent::fake(['must not be sent']);
        try {
            $this->runQueuedJob($run);
        } catch (\Throwable) {
        }

        $run->refresh();
        $this->assertSame(AiRunStatus::Failed, $run->status);
        $this->assertSame(0, AiRunAttempt::query()->where('ai_run_id', $run->id)->count());
        $this->assertNotSame($oldRevision, OrganizationCredential::query()->findOrFail($credential->id)->revision_id);
        $this->assertSame($release->id, $run->execution_candidate_snapshot[0]['model_release_id']);
        $this->assertSame($modelConfig->provider_config_id, $run->execution_candidate_snapshot[0]['provider_configuration_id']);
    }

    public function test_async_worker_fails_closed_while_a_legacy_null_credential_revision_remains(): void
    {
        [$modelConfig] = $this->candidate('openai', 'OpenAI legacy', 'gpt-4o-mini', 1);
        $credential = OrganizationCredential::query()
            ->where('organization_id', $this->organization->id)
            ->where('credential_name', 'OpenAI legacy')
            ->firstOrFail();
        DB::table('organization_credentials')->where('id', $credential->id)->update(['revision_id' => null]);
        AiProviderConfiguration::query()
            ->whereKey($modelConfig->provider_config_id)
            ->update(['tested_credential_revision' => null]);
        Queue::fake();

        $run = app(DispatchAsyncAiRun::class)->handle($this->admin, new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'snapshot-legacy-null-revision',
            promptVersionId: $this->promptVersion->id,
            inputVariables: ['query' => 'queued question'],
        ));

        DynamicWorkflowAgent::fake(['must not be sent']);
        try {
            $this->runQueuedJob($run);
        } catch (\Throwable) {
        }

        $this->assertSame(AiRunStatus::Failed, $run->fresh()->status);
        $this->assertSame(0, AiRunAttempt::query()->where('ai_run_id', $run->id)->count());
    }

    public function test_async_failover_limit_cannot_expand_after_acceptance(): void
    {
        $this->safetyControl(['max_failover_attempts' => 1]);
        $this->candidate('openai', 'OpenAI first', 'arbitrary-primary', 1);
        $this->candidate('anthropic', 'Anthropic second', 'arbitrary-secondary', 2);
        $this->candidate('gemini', 'Gemini third', 'arbitrary-tertiary', 3);
        Queue::fake();

        $run = app(DispatchAsyncAiRun::class)->handle($this->admin, new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'policy-failover-expansion',
            promptVersionId: $this->promptVersion->id,
            inputVariables: ['query' => 'queued question'],
        ));

        $this->assertSame([
            'version' => 1,
            'max_failover_attempts' => 1,
            'max_output_tokens' => 2048,
            'max_tool_calls' => 0,
            'attempt_timeout_seconds' => 60,
            'allowed_tools' => [],
        ], $run->fresh()->execution_policy_snapshot);
        AiOrganizationSafetyControl::query()
            ->where('organization_id', $this->organization->id)
            ->update(['max_failover_attempts' => 3]);

        $providerCalls = 0;
        DynamicWorkflowAgent::fake(function () use (&$providerCalls): string {
            $providerCalls++;
            throw new \RuntimeException('bounded provider failure');
        });

        try {
            $this->runQueuedJob($run);
        } catch (\Throwable) {
        }

        $this->assertSame(1, $providerCalls);
        $this->assertSame(1, AiRunAttempt::query()->where('ai_run_id', $run->id)->count());
    }

    public function test_async_failover_limit_can_tighten_after_acceptance(): void
    {
        $this->safetyControl(['max_failover_attempts' => 3]);
        $this->candidate('openai', 'OpenAI first', 'arbitrary-primary', 1);
        $this->candidate('anthropic', 'Anthropic second', 'arbitrary-secondary', 2);
        $this->candidate('gemini', 'Gemini third', 'arbitrary-tertiary', 3);
        Queue::fake();

        $run = app(DispatchAsyncAiRun::class)->handle($this->admin, new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'policy-failover-tightening',
            promptVersionId: $this->promptVersion->id,
            inputVariables: ['query' => 'queued question'],
        ));

        AiOrganizationSafetyControl::query()
            ->where('organization_id', $this->organization->id)
            ->update(['max_failover_attempts' => 1]);

        $providerCalls = 0;
        DynamicWorkflowAgent::fake(function () use (&$providerCalls): string {
            $providerCalls++;
            throw new \RuntimeException('bounded provider failure');
        });

        try {
            $this->runQueuedJob($run);
        } catch (\Throwable) {
        }

        $this->assertSame(1, $providerCalls);
        $this->assertSame(1, AiRunAttempt::query()->where('ai_run_id', $run->id)->count());
    }

    public function test_async_worker_rechecks_capability_before_provider_io(): void
    {
        $this->safetyControl();
        $this->candidate('openai', 'OpenAI capability boundary', 'arbitrary-capability-model', 1);
        Queue::fake();

        $run = app(DispatchAsyncAiRun::class)->handle($this->admin, new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'capability-boundary-race',
            promptVersionId: $this->promptVersion->id,
            inputVariables: ['query' => 'queued question'],
        ));

        $this->assertTrue(AiOrganizationSafetyControl::query()
            ->where('organization_id', $this->organization->id)
            ->firstOrFail()
            ->isCapabilityEnabled(AiCapability::ClientCompanion->value));

        $capabilityDisabled = false;
        $raceListenerActive = true;
        AiRunAttempt::created(function (AiRunAttempt $attempt) use (&$capabilityDisabled, &$raceListenerActive, $run): void {
            if (! $raceListenerActive || $capabilityDisabled || (int) $attempt->ai_run_id !== (int) $run->id) {
                return;
            }

            AiOrganizationSafetyControl::query()
                ->where('organization_id', $this->organization->id)
                ->firstOrFail()
                ->update(['disabled_capabilities' => [AiCapability::ClientCompanion->value]]);
            $capabilityDisabled = true;
        });

        $providerCalls = 0;
        DynamicWorkflowAgent::fake(function () use (&$providerCalls): string {
            $providerCalls++;

            return 'must not be sent';
        });

        try {
            $this->runQueuedJob($run);
        } finally {
            $raceListenerActive = false;
        }

        $attempt = AiRunAttempt::query()->where('ai_run_id', $run->id)->firstOrFail();
        $run = AiRun::query()->whereKey($run->id)->firstOrFail();

        $this->assertTrue($capabilityDisabled);
        $this->assertSame(0, $providerCalls);
        $this->assertSame(AiRunStatus::Failed, $run->status);
        $this->assertSame(AiErrorCategory::SafetyKillSwitchActive, $run->error_category);
        $this->assertSame('failed', $attempt->status);
        $this->assertSame(AiErrorCategory::SafetyKillSwitchActive, $attempt->error_category);
        $this->assertSame(BudgetReservationStatus::ConservativelyCharged, $attempt->budget_reservation_status);
        $this->assertSame(0, (int) DB::table('ai_organization_daily_budgets')
            ->where('organization_id', $this->organization->id)
            ->whereDate('usage_date', now()->toDateString())
            ->value('reserved_minor_units'));
    }

    public function test_async_output_token_limit_cannot_expand_after_acceptance(): void
    {
        $this->safetyControl(['max_tokens_per_run' => 512]);
        $this->candidate('openai', 'OpenAI tokens', 'arbitrary-token-model', 1);
        Queue::fake();
        $run = app(DispatchAsyncAiRun::class)->handle($this->admin, new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'policy-token-expansion',
            promptVersionId: $this->promptVersion->id,
            inputVariables: ['query' => 'queued question'],
        ));
        AiOrganizationSafetyControl::query()
            ->where('organization_id', $this->organization->id)
            ->update(['max_tokens_per_run' => 2048]);

        $observedMaxTokens = null;
        Event::fake([PromptingAgent::class]);
        DynamicWorkflowAgent::fake(['policy response']);
        $this->runQueuedJob($run);
        Event::assertDispatched(PromptingAgent::class, function (PromptingAgent $event) use (&$observedMaxTokens): bool {
            $observedMaxTokens = $event->prompt->agent->maxTokens();

            return true;
        });

        $this->assertSame(512, $observedMaxTokens);
    }

    public function test_async_tool_access_cannot_expand_after_acceptance(): void
    {
        $this->promptVersion->update(['allowed_tools' => ['search_knowledge_base']]);
        $this->safetyControl(['disabled_tools' => ['search_knowledge_base']]);
        $this->candidate('openai', 'OpenAI tool expansion', 'arbitrary-tool-model', 1);
        Queue::fake();
        $run = app(DispatchAsyncAiRun::class)->handle($this->admin, new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'policy-tool-expansion',
            promptVersionId: $this->promptVersion->id,
            inputVariables: ['query' => 'queued question'],
        ));
        AiOrganizationSafetyControl::query()
            ->where('organization_id', $this->organization->id)
            ->update(['disabled_tools' => []]);

        $observedToolCount = null;
        Event::fake([PromptingAgent::class]);
        DynamicWorkflowAgent::fake(['policy response']);
        $this->runQueuedJob($run);
        Event::assertDispatched(PromptingAgent::class, function (PromptingAgent $event) use (&$observedToolCount): bool {
            $observedToolCount = count(iterator_to_array($event->prompt->agent->tools()));

            return true;
        });

        $this->assertSame([], $run->fresh()->execution_policy_snapshot['allowed_tools']);
        $this->assertSame(0, $observedToolCount);
    }

    public function test_async_tool_access_can_tighten_after_acceptance(): void
    {
        $this->promptVersion->update(['allowed_tools' => ['search_knowledge_base']]);
        $this->safetyControl();
        $this->candidate('openai', 'OpenAI tool tightening', 'arbitrary-tool-model', 1);
        Queue::fake();
        $run = app(DispatchAsyncAiRun::class)->handle($this->admin, new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'policy-tool-tightening',
            promptVersionId: $this->promptVersion->id,
            inputVariables: ['query' => 'queued question'],
        ));
        AiOrganizationSafetyControl::query()
            ->where('organization_id', $this->organization->id)
            ->update(['disabled_tools' => ['search_knowledge_base']]);

        $observedToolCount = null;
        Event::fake([PromptingAgent::class]);
        DynamicWorkflowAgent::fake(['policy response']);
        $this->runQueuedJob($run);
        Event::assertDispatched(PromptingAgent::class, function (PromptingAgent $event) use (&$observedToolCount): bool {
            $observedToolCount = count(iterator_to_array($event->prompt->agent->tools()));

            return true;
        });

        $this->assertSame(['search_knowledge_base'], $run->fresh()->execution_policy_snapshot['allowed_tools']);
        $this->assertSame(0, $observedToolCount);
    }

    public function test_async_timeout_cannot_expand_after_acceptance(): void
    {
        $this->safetyControl(['default_timeout_seconds' => 10]);
        $this->candidate('openai', 'OpenAI timeout', 'arbitrary-timeout-model', 1);
        Queue::fake();
        $run = app(DispatchAsyncAiRun::class)->handle($this->admin, new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'policy-timeout-expansion',
            promptVersionId: $this->promptVersion->id,
            inputVariables: ['query' => 'queued question'],
        ));
        AiOrganizationSafetyControl::query()
            ->where('organization_id', $this->organization->id)
            ->update(['default_timeout_seconds' => 60]);

        $observedTimeout = null;
        Event::fake([PromptingAgent::class]);
        DynamicWorkflowAgent::fake(['policy response']);
        $this->runQueuedJob($run);
        Event::assertDispatched(PromptingAgent::class, function (PromptingAgent $event) use (&$observedTimeout): bool {
            $observedTimeout = $event->prompt->timeout;

            return true;
        });

        $this->assertSame(10, $observedTimeout);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function safetyControl(array $attributes = []): AiOrganizationSafetyControl
    {
        return AiOrganizationSafetyControl::create(array_merge([
            'organization_id' => $this->organization->id,
        ], $attributes));
    }

    /**
     * @param  list<AiModelModality>  $modalities
     * @return array{0: AiModelConfiguration, 1: AiModelRelease}
     */
    private function candidateWithCapabilities(
        string $providerName,
        string $credentialName,
        string $modelName,
        int $priority,
        AiCapability $capability = AiCapability::ClientCompanion,
        array $modalities = [],
    ): array {
        return $this->candidate($providerName, $credentialName, $modelName, $priority, $capability, $modalities);
    }

    /**
     * @param  list<AiModelModality>  $modalities
     * @return array{0: AiModelConfiguration, 1: AiModelRelease}
     */
    private function candidate(
        string $providerName,
        string $credentialName,
        string $modelName,
        int $priority,
        AiCapability $capability = AiCapability::ClientCompanion,
        array $modalities = [],
    ): array {
        $credential = new OrganizationCredential([
            'provider' => $providerName,
            'credential_name' => $credentialName,
            'revision_id' => (string) Str::uuid(),
        ]);
        $credential->organization_id = $this->organization->id;
        $credential->credentials = ['api_key' => 'test-key'];
        $credential->status = CredentialStatus::Active;
        $credential->save();

        $provider = AiProviderConfiguration::create([
            'organization_id' => $this->organization->id,
            'provider_name' => $providerName,
            'display_name' => $providerName,
            'is_enabled' => true,
            'health_status' => ProviderHealthStatus::Healthy,
            'credential_id' => $credential->id,
            'tested_credential_revision' => $credential->revision_id,
            'tested_configuration_digest' => AiProviderExecutionConfiguration::digest($providerName),
        ]);
        $pricing = new AiPricingSnapshot(
            currency: 'USD',
            inputCostPerMillionMinorUnits: 1,
            outputCostPerMillionMinorUnits: 1,
        );
        $capabilities = array_merge(
            [$capability->value],
            array_map(static fn (AiModelModality $modality): string => $modality->value, $modalities),
        );
        $modelConfig = AiModelConfiguration::create([
            'organization_id' => $this->organization->id,
            'provider_config_id' => $provider->id,
            'model_name' => $modelName,
            'display_name' => $modelName,
            'capabilities' => $capabilities,
            'pricing_snapshot' => $pricing->toArray(),
            'failover_priority' => $priority,
        ]);
        $release = AiModelRelease::create([
            'organization_id' => $this->organization->id,
            'model_config_id' => $modelConfig->id,
            'release_number' => 1,
            'status' => 'active',
            'provider_name' => $providerName,
            'model_name' => $modelName,
            'capabilities' => $capabilities,
            'pricing_snapshot' => $pricing->toArray(),
            'activated_at' => now(),
        ]);
        $modelConfig->update(['active_release_id' => $release->id]);

        return [$modelConfig->fresh(), $release->fresh()];
    }

    private function runQueuedJob(AiRun $run): void
    {
        (new ProcessAiRunJob(
            organizationId: $this->organization->id,
            runId: $run->id,
        ))->handle(app(AiWorkflowEngine::class), app(ReconcileExpiredAiRun::class));
    }

    private function createMedicalReportAttachment(): MedicalAttachment
    {
        $client = Client::factory()->forOrganization($this->organization)->create();
        $content = '%PDF-1.7 async bounded attachment test';
        $uuid = (string) Str::uuid();
        $path = "medical/attachments/{$this->organization->id}/{$uuid}.pdf";
        Storage::disk('private')->put($path, $content);

        return MedicalAttachment::create([
            'uuid' => $uuid,
            'organization_id' => $this->organization->id,
            'client_id' => $client->id,
            'uploaded_by_user_id' => $this->admin->id,
            'attachment_type' => AttachmentType::MedicalReport,
            'disk' => 'private',
            'storage_path' => $path,
            'original_filename' => 'async-bounded-report.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($content),
            'sha256_checksum' => hash('sha256', $content),
            'scan_status' => AttachmentScanStatus::Cleared,
            'scanned_at' => now(),
        ]);
    }
}
