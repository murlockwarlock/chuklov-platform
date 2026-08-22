<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\CreateAndActivateModelRelease;
use App\Modules\AI\Application\Actions\TestProviderConnection;
use App\Modules\AI\Application\Actions\UpdateAiProviderConfiguration;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Models\AiRunAttempt;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Infrastructure\Engine\DynamicWorkflowAgent;
use App\Modules\AI\Infrastructure\Providers\AiProviderExecutionConfiguration;
use App\Modules\AI\Infrastructure\Providers\AiProviderFactory;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\ReplaceOrganizationCredential;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

class AiProviderCredentialTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organizationA;

    private Organization $organizationB;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationA = Organization::create([
            'name' => 'Clinic Alpha',
            'slug' => 'clinic-alpha',
        ]);

        $this->organizationB = Organization::create([
            'name' => 'Clinic Beta',
            'slug' => 'clinic-beta',
        ]);

        $this->userA = User::factory()->forOrganization($this->organizationA, OrganizationRole::Administrator)->create();

        config()->set('tenancy.default_organization_id', $this->organizationA->id);
        app(OrganizationContext::class)->set($this->organizationA);

        $prompt = AiPrompt::create([
            'organization_id' => $this->organizationA->id,
            'key' => 'provider_default_prompt',
            'name' => 'Provider default prompt',
            'capability' => AiCapability::ClientCompanion,
        ]);
        $version = AiPromptVersion::create([
            'organization_id' => $this->organizationA->id,
            'prompt_id' => $prompt->id,
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'Use the versioned provider instructions.',
            'user_prompt_template' => '{{query}}',
            'context_policy' => ['include_rag' => true],
            'allowed_tools' => ['search_knowledge_base'],
            'activated_at' => Carbon::now(),
        ]);
        $prompt->update(['active_version_id' => $version->id]);
    }

    public function test_organization_credentials_are_isolated_per_tenant_without_global_config_mutation(): void
    {
        $credA = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'Org A Key',
            'revision_id' => '00000000-0000-4000-8000-000000000001',
        ]);
        $credA->organization_id = max(0, (int) $this->organizationA->id);
        $credA->credentials = ['api_key' => 'sk-org-a-secret-key'];
        $credA->status = CredentialStatus::Active;
        $credA->save();

        $credB = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'Org B Key',
            'revision_id' => '00000000-0000-4000-8000-000000000002',
        ]);
        $credB->organization_id = max(0, (int) $this->organizationB->id);
        $credB->credentials = ['api_key' => 'sk-org-b-secret-key'];
        $credB->status = CredentialStatus::Active;
        $credB->save();

        $factory = app(AiProviderFactory::class);

        $providerInstanceA = $factory->createTextProvider('openai', $credA);
        $providerInstanceB = $factory->createTextProvider('openai', $credB);

        // Verify provider A credentials
        $credsA = $providerInstanceA->providerCredentials();
        $this->assertSame('sk-org-a-secret-key', $credsA['key']);

        // Verify provider B credentials
        $credsB = $providerInstanceB->providerCredentials();
        $this->assertSame('sk-org-b-secret-key', $credsB['key']);

        // Ensure global config was NOT mutated
        $this->assertNotSame('sk-org-a-secret-key', config('ai.providers.openai.key'));
        $this->assertNotSame('sk-org-b-secret-key', config('ai.providers.openai.key'));
    }

    public function test_openai_responses_request_is_forced_stateless_even_when_extra_configuration_requests_storage(): void
    {
        $credential = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'OpenAI Stateless',
            'revision_id' => (string) Str::uuid(),
        ]);
        $credential->organization_id = $this->organizationA->id;
        $credential->credentials = ['api_key' => 'sk-stateless-test'];
        $credential->status = CredentialStatus::Active;
        $credential->save();

        $requestBody = null;
        Http::fake([
            'https://api.openai.com/v1/responses' => function ($request) use (&$requestBody) {
                $requestBody = $request->data();

                return Http::response([
                    'id' => 'resp_stateless_test',
                    'model' => 'gpt-4o-mini',
                    'status' => 'completed',
                    'output' => [[
                        'type' => 'message',
                        'role' => 'assistant',
                        'status' => 'completed',
                        'content' => [[
                            'type' => 'output_text',
                            'text' => 'safe response',
                            'annotations' => [],
                        ]],
                    ]],
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                ]);
            },
        ]);

        $agent = new DynamicWorkflowAgent(instructionsText: 'Answer safely.');
        $provider = app(AiProviderFactory::class)->createTextProvider(
            providerName: 'openai',
            credential: $credential,
            agent: $agent,
            extraConfig: ['store' => true],
        );
        $provider->prompt(new AgentPrompt(
            agent: $agent,
            prompt: 'Protected request',
            attachments: [],
            provider: $provider,
            model: 'gpt-4o-mini',
        ));

        $this->assertIsArray($requestBody);
        $this->assertFalse($requestBody['store']);
        Http::assertSentCount(1);
    }

    public function test_immutable_model_release_regression_preserves_historical_provenance(): void
    {
        DynamicWorkflowAgent::fake(['Response from Model Release 1', 'Response from Model Release 2']);

        config()->set('tenancy.default_organization_id', $this->organizationA->id);
        app(OrganizationContext::class)->set($this->organizationA);

        $credential = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'OpenAI Prod',
            'revision_id' => '00000000-0000-4000-8000-000000000010',
        ]);
        $credential->organization_id = max(0, (int) $this->organizationA->id);
        $credential->credentials = ['api_key' => 'sk-test'];
        $credential->status = CredentialStatus::Active;
        $credential->save();

        $provider = AiProviderConfiguration::create([
            'organization_id' => $this->organizationA->id,
            'provider_name' => 'openai',
            'display_name' => 'OpenAI',
            'is_enabled' => true,
            'health_status' => ProviderHealthStatus::Healthy,
            'credential_id' => $credential->id,
            'tested_credential_revision' => $credential->revision_id,
            'tested_configuration_digest' => AiProviderExecutionConfiguration::digest('openai'),
        ]);

        $pricing1 = new AiPricingSnapshot(currency: 'USD', inputCostPerMillionMinorUnits: 15, outputCostPerMillionMinorUnits: 60);

        $modelConfig = AiModelConfiguration::create([
            'organization_id' => $this->organizationA->id,
            'provider_config_id' => $provider->id,
            'model_name' => 'gpt-4o-mini',
            'display_name' => 'GPT-4o Mini',
            'is_enabled' => true,
            'capabilities' => [AiCapability::ClientCompanion->value],
            'pricing_snapshot' => $pricing1->toArray(),
            'failover_priority' => 1,
        ]);

        $release1 = AiModelRelease::create([
            'organization_id' => $this->organizationA->id,
            'model_config_id' => $modelConfig->id,
            'release_number' => 1,
            'status' => 'active',
            'provider_name' => 'openai',
            'model_name' => 'gpt-4o-mini',
            'capabilities' => [AiCapability::ClientCompanion->value],
            'pricing_snapshot' => $pricing1->toArray(),
            'activated_at' => Carbon::now()->subHour(),
        ]);
        $modelConfig->update(['active_release_id' => $release1->id]);

        /** @var AiWorkflowEngine $engine */
        $engine = app(AiWorkflowEngine::class);

        // 1. Run 1 executes against Release 1
        $result1 = $engine->run($this->organizationA->id, new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'release_test_1',
            inputVariables: ['query' => 'Q1'],
        ));
        $this->assertTrue($result1->isSuccess());

        $attempt1 = AiRunAttempt::where('ai_run_id', $result1->runId)->first();
        $this->assertNotNull($attempt1);
        $this->assertSame($release1->id, $attempt1->model_release_id);
        $this->assertSame('gpt-4o-mini', $attempt1->model);

        // 2. Activate Release 2 (e.g. upgraded to gpt-4o with new pricing)
        /** @var CreateAndActivateModelRelease $activateAction */
        $activateAction = app(CreateAndActivateModelRelease::class);
        $release2 = $activateAction->handle($this->userA, $modelConfig, [
            'model_name' => 'gpt-4o',
            'display_name' => 'GPT-4o Standard',
            'input_cost_per_million' => 250,
            'output_cost_per_million' => 1000,
            'capabilities' => [AiCapability::ClientCompanion->value],
        ]);

        $this->assertSame(2, $release2->release_number);
        $this->assertSame('active', $release2->status);
        $release1->refresh();
        $this->assertSame('retired', $release1->status);

        // 3. Run 2 executes against Release 2
        $result2 = $engine->run($this->organizationA->id, new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'release_test_2',
            inputVariables: ['query' => 'Q2'],
        ));
        $this->assertTrue($result2->isSuccess());

        $attempt2 = AiRunAttempt::where('ai_run_id', $result2->runId)->first();
        $this->assertNotNull($attempt2);
        $this->assertSame($release2->id, $attempt2->model_release_id);
        $this->assertSame('gpt-4o', $attempt2->model);

        // 4. Verify historical Attempt 1 remains intact pointing to Release 1
        $attempt1->refresh();
        $this->assertSame($release1->id, $attempt1->model_release_id);
        $this->assertSame('gpt-4o-mini', $attempt1->model);
    }

    public function test_provider_connection_test_action_updates_health_status(): void
    {
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response(['data' => []], 200),
        ]);

        $credential = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'OpenAI Health Test',
            'revision_id' => '00000000-0000-4000-8000-000000000020',
        ]);
        $credential->organization_id = max(0, (int) $this->organizationA->id);
        $credential->credentials = ['api_key' => 'sk-valid-key'];
        $credential->status = CredentialStatus::Active;
        $credential->save();

        $provider = AiProviderConfiguration::create([
            'organization_id' => $this->organizationA->id,
            'provider_name' => 'openai',
            'display_name' => 'OpenAI Test',
            'is_enabled' => true,
            'credential_id' => $credential->id,
            'health_status' => ProviderHealthStatus::Unavailable,
        ]);

        /** @var TestProviderConnection $action */
        $action = app(TestProviderConnection::class);

        $result = $action->handle($this->userA, $provider->id);

        $this->assertTrue($result['success']);

        $provider->refresh();
        $this->assertSame(ProviderHealthStatus::Healthy, $provider->health_status);
        $this->assertNotNull($provider->last_checked_at);
        $this->assertNull($provider->last_health_error);
        $this->assertSame($credential->revision_id, $provider->tested_credential_revision);
        $this->assertSame(AiProviderExecutionConfiguration::digest('openai'), $provider->tested_configuration_digest);
    }

    public function test_credential_rotation_invalidates_provider_health_until_the_new_revision_is_probed(): void
    {
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response(['data' => []], 200),
        ]);

        $credential = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'OpenAI Rotating Health',
            'revision_id' => (string) Str::uuid(),
        ]);
        $credential->organization_id = $this->organizationA->id;
        $credential->credentials = ['api_key' => 'sk-revision-a'];
        $credential->status = CredentialStatus::Active;
        $credential->save();

        $provider = AiProviderConfiguration::create([
            'organization_id' => $this->organizationA->id,
            'provider_name' => 'openai',
            'display_name' => 'OpenAI rotating health',
            'credential_id' => $credential->id,
            'health_status' => ProviderHealthStatus::Healthy,
            'tested_credential_revision' => $credential->revision_id,
            'tested_configuration_digest' => AiProviderExecutionConfiguration::digest('openai'),
        ]);

        $updatedCredential = app(ReplaceOrganizationCredential::class)->handle(
            actor: $this->userA,
            provider: 'openai',
            credentialName: 'OpenAI Rotating Health',
            credentials: ['api_key' => 'sk-revision-b'],
        );

        $provider->refresh();
        $this->assertNotSame($credential->revision_id, $updatedCredential->revision_id);
        $this->assertSame(ProviderHealthStatus::Unknown, $provider->health_status);
        $this->assertNull($provider->tested_credential_revision);
        $this->assertNull($provider->tested_configuration_digest);

        $result = app(TestProviderConnection::class)->handle($this->userA, $provider->id);

        $this->assertTrue($result['success']);
        $provider->refresh();
        $this->assertSame(ProviderHealthStatus::Healthy, $provider->health_status);
        $this->assertSame($updatedCredential->revision_id, $provider->tested_credential_revision);
    }

    public function test_provider_credential_reassignment_invalidates_previous_health_provenance(): void
    {
        $credentialA = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'OpenAI Assignment A',
            'revision_id' => (string) Str::uuid(),
        ]);
        $credentialA->organization_id = $this->organizationA->id;
        $credentialA->credentials = ['api_key' => 'sk-assignment-a'];
        $credentialA->status = CredentialStatus::Active;
        $credentialA->save();

        $credentialB = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'OpenAI Assignment B',
            'revision_id' => (string) Str::uuid(),
        ]);
        $credentialB->organization_id = $this->organizationA->id;
        $credentialB->credentials = ['api_key' => 'sk-assignment-b'];
        $credentialB->status = CredentialStatus::Active;
        $credentialB->save();

        $provider = AiProviderConfiguration::create([
            'organization_id' => $this->organizationA->id,
            'provider_name' => 'openai',
            'display_name' => 'OpenAI assignment',
            'credential_id' => $credentialA->id,
            'health_status' => ProviderHealthStatus::Healthy,
            'tested_credential_revision' => $credentialA->revision_id,
            'tested_configuration_digest' => AiProviderExecutionConfiguration::digest('openai'),
        ]);

        app(UpdateAiProviderConfiguration::class)->handle($this->userA, $provider, [
            'credential_id' => $credentialB->id,
        ]);

        $provider->refresh();
        $this->assertSame($credentialB->id, $provider->credential_id);
        $this->assertSame(ProviderHealthStatus::Unknown, $provider->health_status);
        $this->assertNull($provider->tested_credential_revision);
        $this->assertNull($provider->tested_configuration_digest);
    }

    public function test_custom_provider_probe_endpoint_is_rejected_before_any_request(): void
    {
        Http::fake();

        $credential = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'OpenAI Custom Endpoint',
            'revision_id' => (string) Str::uuid(),
        ]);
        $credential->organization_id = $this->organizationA->id;
        $credential->credentials = ['api_key' => 'sk-custom-endpoint'];
        $credential->status = CredentialStatus::Active;
        $credential->save();

        $provider = AiProviderConfiguration::create([
            'organization_id' => $this->organizationA->id,
            'provider_name' => 'openai',
            'display_name' => 'OpenAI custom endpoint',
            'credential_id' => $credential->id,
        ]);

        try {
            app(UpdateAiProviderConfiguration::class)->handle($this->userA, $provider, [
                'options' => ['base_url' => 'https://attacker.example/v1'],
            ]);
            $this->fail('Expected arbitrary custom provider endpoint to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Custom provider endpoints', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_stored_custom_provider_endpoint_is_not_used_for_a_credential_probe(): void
    {
        Http::fake();

        $credential = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'OpenAI Stored Custom Endpoint',
            'revision_id' => (string) Str::uuid(),
        ]);
        $credential->organization_id = $this->organizationA->id;
        $credential->credentials = ['api_key' => 'sk-stored-custom-endpoint'];
        $credential->status = CredentialStatus::Active;
        $credential->save();

        $provider = AiProviderConfiguration::create([
            'organization_id' => $this->organizationA->id,
            'provider_name' => 'openai',
            'display_name' => 'OpenAI stored custom endpoint',
            'credential_id' => $credential->id,
            'options' => ['url' => 'https://attacker.example/v1'],
        ]);

        $result = app(TestProviderConnection::class)->handle($this->userA, $provider->id);

        $this->assertFalse($result['success']);
        $provider->refresh();
        $this->assertSame(ProviderHealthStatus::Unknown, $provider->health_status);
        Http::assertNothingSent();
    }

    public function test_canonical_probe_does_not_follow_redirects_with_credentials(): void
    {
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response('', 302, ['Location' => 'https://attacker.example/collect']),
            '*' => Http::response(['unexpected' => true], 200),
        ]);

        $credential = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'OpenAI Redirect Probe',
            'revision_id' => (string) Str::uuid(),
        ]);
        $credential->organization_id = $this->organizationA->id;
        $credential->credentials = ['api_key' => 'sk-redirect-secret'];
        $credential->status = CredentialStatus::Active;
        $credential->save();

        $provider = AiProviderConfiguration::create([
            'organization_id' => $this->organizationA->id,
            'provider_name' => 'openai',
            'display_name' => 'OpenAI redirect probe',
            'credential_id' => $credential->id,
        ]);

        $result = app(TestProviderConnection::class)->handle($this->userA, $provider->id);

        $this->assertFalse($result['success']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.com/v1/models'
            && $request->hasHeader('Authorization', 'Bearer sk-redirect-secret'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'attacker.example'));
    }

    public function test_credentialless_ollama_probe_uses_the_local_models_endpoint(): void
    {
        Http::fake([
            'http://localhost:11434/api/tags' => Http::response(['models' => []], 200),
        ]);

        $provider = AiProviderConfiguration::create([
            'organization_id' => $this->organizationA->id,
            'provider_name' => 'ollama',
            'display_name' => 'Ollama',
            'is_enabled' => true,
        ]);

        $result = app(TestProviderConnection::class)->handle($this->userA, $provider->id);

        $this->assertTrue($result['success']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'http://localhost:11434/api/tags');
        $provider->refresh();
        $this->assertSame(ProviderHealthStatus::Healthy, $provider->health_status);
        $this->assertNull($provider->tested_credential_revision);
    }

    public function test_failed_authenticated_probe_is_degraded_with_sanitized_error(): void
    {
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response(['error' => 'secret provider response'], 401),
        ]);

        $credential = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'OpenAI Failed Health Test',
            'revision_id' => '00000000-0000-4000-8000-000000000040',
        ]);
        $credential->organization_id = $this->organizationA->id;
        $credential->credentials = ['api_key' => 'sk-secret-health'];
        $credential->status = CredentialStatus::Active;
        $credential->save();

        $provider = AiProviderConfiguration::create([
            'organization_id' => $this->organizationA->id,
            'provider_name' => 'openai',
            'display_name' => 'OpenAI Failed Test',
            'is_enabled' => true,
            'credential_id' => $credential->id,
        ]);

        $result = app(TestProviderConnection::class)->handle($this->userA, $provider->id);

        $this->assertFalse($result['success']);
        $provider->refresh();
        $this->assertSame(ProviderHealthStatus::Degraded, $provider->health_status);
        $this->assertSame('An internal error occurred during AI execution.', $provider->last_health_error);
        $this->assertStringNotContainsString('sk-secret-health', (string) $provider->last_health_error);
        $this->assertStringNotContainsString('secret provider response', (string) $provider->last_health_error);
    }
}
