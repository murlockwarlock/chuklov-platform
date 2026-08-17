<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\DispatchAsyncAiRun;
use App\Modules\AI\Application\Actions\ReconcileExpiredAiRun;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunAttempt;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Infrastructure\Engine\DynamicWorkflowAgent;
use App\Modules\AI\Infrastructure\Jobs\ProcessAiRunJob;
use App\Modules\AI\Infrastructure\Providers\AiProviderExecutionConfiguration;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\ReplaceOrganizationCredential;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AiAsyncCandidateSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private AiPromptVersion $promptVersion;

    protected function setUp(): void
    {
        parent::setUp();

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

        [, $newRelease] = $this->candidate('gemini', 'Gemini new', 'gemini-2.0-flash', 0);
        AiModelConfiguration::query()->whereKey($firstRelease->model_config_id)->update(['failover_priority' => 99]);

        DynamicWorkflowAgent::fake(['snapshot response']);
        $this->runQueuedJob($run);

        $attempts = AiRunAttempt::query()->where('ai_run_id', $run->id)->orderBy('attempt_number')->get();
        $this->assertCount(1, $attempts);
        $this->assertSame($firstRelease->id, $attempts->first()->model_release_id);
        $this->assertNotSame($newRelease->id, $attempts->first()->model_release_id);
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

    /** @return array{0: AiModelConfiguration, 1: AiModelRelease} */
    private function candidate(string $providerName, string $credentialName, string $modelName, int $priority): array
    {
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
        $modelConfig = AiModelConfiguration::create([
            'organization_id' => $this->organization->id,
            'provider_config_id' => $provider->id,
            'model_name' => $modelName,
            'display_name' => $modelName,
            'capabilities' => [AiCapability::ClientCompanion->value],
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
            'capabilities' => [AiCapability::ClientCompanion->value],
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
}
