<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\CreateAndActivateModelRelease;
use App\Modules\AI\Application\Actions\TestProviderConnection;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Models\AiRunAttempt;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Infrastructure\Engine\DynamicWorkflowAgent;
use App\Modules\AI\Infrastructure\Providers\AiProviderFactory;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    }

    public function test_organization_credentials_are_isolated_per_tenant_without_global_config_mutation(): void
    {
        $credA = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'Org A Key',
            'revision_id' => 'rev-a-1111',
        ]);
        $credA->organization_id = max(0, (int) $this->organizationA->id);
        $credA->credentials = ['api_key' => 'sk-org-a-secret-key'];
        $credA->status = CredentialStatus::Active;
        $credA->save();

        $credB = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'Org B Key',
            'revision_id' => 'rev-b-2222',
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

    public function test_immutable_model_release_regression_preserves_historical_provenance(): void
    {
        DynamicWorkflowAgent::fake(['Response from Model Release 1', 'Response from Model Release 2']);

        config()->set('tenancy.default_organization_id', $this->organizationA->id);
        app(OrganizationContext::class)->set($this->organizationA);

        $credential = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'OpenAI Prod',
            'revision_id' => 'rev-001',
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
            'credential_id' => $credential->id,
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
        $credential = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'OpenAI Health Test',
            'revision_id' => 'rev-health-1',
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
    }
}
