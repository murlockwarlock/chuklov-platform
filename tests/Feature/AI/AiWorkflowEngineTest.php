<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\BudgetReservationStatus;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Exceptions\AiKillSwitchException;
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
use App\Modules\AI\Domain\ValueObjects\AiInputReference;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Infrastructure\Engine\DynamicWorkflowAgent;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_workflow_engine_executes_run_and_creates_encrypted_class_c_payload(): void
    {
        DynamicWorkflowAgent::fake(['{"summary": "Извлеченные факты", "document_type": "epicrisis", "extracted_facts": ["Анамнез без особенностей"]}']);

        $credential = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'OpenAI Production',
            'revision_id' => (string) Str::uuid(),
        ]);
        $credential->organization_id = max(0, (int) $this->organization->id);
        $credential->credentials = ['api_key' => 'sk-test'];
        $credential->status = CredentialStatus::Active;
        $credential->save();

        $provider = AiProviderConfiguration::create([
            'organization_id' => $this->organization->id,
            'provider_name' => 'openai',
            'display_name' => 'OpenAI',
            'is_enabled' => true,
            'credential_id' => $credential->id,
        ]);

        $pricing = new AiPricingSnapshot(currency: 'USD', inputCostPerMillionMinorUnits: 15, outputCostPerMillionMinorUnits: 60);

        $model = AiModelConfiguration::create([
            'organization_id' => $this->organization->id,
            'provider_config_id' => $provider->id,
            'model_name' => 'gpt-4o-mini',
            'display_name' => 'GPT-4o Mini',
            'is_enabled' => true,
            'capabilities' => [AiCapability::ClinicalDocumentExtraction->value],
            'pricing_snapshot' => $pricing->toArray(),
            'failover_priority' => 1,
        ]);

        $release = AiModelRelease::create([
            'organization_id' => $this->organization->id,
            'model_config_id' => $model->id,
            'release_number' => 1,
            'status' => 'active',
            'provider_name' => 'openai',
            'model_name' => 'gpt-4o-mini',
            'capabilities' => [AiCapability::ClinicalDocumentExtraction->value],
            'pricing_snapshot' => $pricing->toArray(),
            'activated_at' => Carbon::now(),
        ]);
        $model->update(['active_release_id' => $release->id]);

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
        $this->assertSame($credential->revision_id, $attempt->credential_revision);
        $this->assertSame(BudgetReservationStatus::Settled, $attempt->budget_reservation_status);
        $this->assertNotNull($attempt->settled_estimated_cost_minor_units);
    }

    public function test_workflow_engine_fails_closed_when_daily_spend_budget_exceeded(): void
    {
        AiOrganizationSafetyControl::create([
            'organization_id' => $this->organization->id,
            'is_ai_globally_enabled' => true,
            'max_daily_spend_minor_units' => 10, // Very low limit (10 minor units = $0.001)
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

        $this->expectException(\InvalidArgumentException::class);
        $engine->run($this->organization->id, $request);
    }
}
