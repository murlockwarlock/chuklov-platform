<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\CreateAndActivateModelRelease;
use App\Modules\AI\Application\Actions\CreateEvalCase;
use App\Modules\AI\Application\Actions\RunEvaluationSuite;
use App\Modules\AI\Application\Actions\UpdateEvalCase;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Models\AiEvalCase;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Infrastructure\Engine\DynamicWorkflowAgent;
use App\Modules\AI\Infrastructure\Providers\AiProviderExecutionConfiguration;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class AiEvaluationSuiteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    private AiModelConfiguration $modelConfiguration;

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

        $this->modelConfiguration = $this->setupConfiguredModel(AiCapability::PostureAnalysis);
    }

    private function setupConfiguredModel(AiCapability $capability, string $providerName = 'openai', string $modelName = 'gpt-4o-mini'): AiModelConfiguration
    {
        $credential = new OrganizationCredential([
            'provider' => $providerName,
            'credential_name' => "{$providerName} Prod",
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
            'is_enabled' => true,
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
            'is_enabled' => true,
            'capabilities' => [$capability->value],
            'pricing_snapshot' => $pricing->toArray(),
            'failover_priority' => 1,
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

    public function test_run_evaluation_suite_executes_cases_and_checks_assertions(): void
    {
        DynamicWorkflowAgent::fake([
            '{"symmetry_observations": ["Правильная осанка и симметрия"], "posture_type": "норма", "recommendations": "Продолжать упражнения"}',
            '{"symmetry_observations": ["Без совпадений"], "posture_type": "кифоз", "recommendations": ""}',
        ]);

        $prompt = AiPrompt::create([
            'organization_id' => $this->organization->id,
            'key' => 'eval_test_prompt',
            'name' => 'Промпт для тестов',
            'capability' => AiCapability::PostureAnalysis,
        ]);

        $version = AiPromptVersion::create([
            'organization_id' => $this->organization->id,
            'prompt_id' => $prompt->id,
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'Оцените осанку.',
            'user_prompt_template' => 'Запрос: {{query}}',
            'activated_at' => Carbon::now(),
        ]);
        $prompt->update(['active_version_id' => $version->id]);

        $suite = AiEvalSuite::create([
            'organization_id' => $this->organization->id,
            'key' => 'posture_eval_suite',
            'name' => 'Тесты анализа осанки',
            'capability' => AiCapability::PostureAnalysis,
            'prompt_id' => $prompt->id,
        ]);

        AiEvalCase::create([
            'organization_id' => $this->organization->id,
            'eval_suite_id' => $suite->id,
            'name' => 'Кейс 1: Симметрия',
            'is_synthetic' => true,
            'is_deidentified' => false,
            'test_inputs' => ['query' => 'Оценка симметрии'],
            'expected_assertions' => ['contains_text' => 'симметрия'],
            'is_active' => true,
        ]);

        AiEvalCase::create([
            'organization_id' => $this->organization->id,
            'eval_suite_id' => $suite->id,
            'name' => 'Кейс 2: Сколиоз',
            'is_synthetic' => true,
            'is_deidentified' => false,
            'test_inputs' => ['query' => 'Оценка сколиоза'],
            'expected_assertions' => ['contains_text' => 'сколиоз'],
            'is_active' => true,
        ]);

        /** @var RunEvaluationSuite $runner */
        $runner = app(RunEvaluationSuite::class);

        $evalRun = $runner->handle(
            actor: $this->user,
            evalSuiteId: $suite->id,
            promptVersionId: $version->id,
            modelReleaseId: AiModelRelease::query()->where('organization_id', $this->organization->id)->value('id'),
        );

        $this->assertSame(2, $evalRun->total_cases);
        $this->assertSame(1, $evalRun->passed_cases); // Case 1 passed, Case 2 failed assertion
        $this->assertSame(1, $evalRun->failed_cases);
    }

    public function test_create_eval_case_rejects_real_patient_references_and_unclassified_inputs(): void
    {
        $suite = AiEvalSuite::create([
            'organization_id' => $this->organization->id,
            'key' => 'privacy_eval_suite',
            'name' => 'Сьют проверки приватности',
            'capability' => AiCapability::PostureAnalysis,
        ]);

        $client = new Client(['full_name' => 'Реальный Пациент']);
        $client->organization_id = max(0, (int) $this->organization->id);
        $client->save();

        $action = app(CreateEvalCase::class);

        // 1. Rejects unclassified (neither synthetic nor de-identified)
        try {
            $action->execute(
                actor: $this->user,
                organization: $this->organization,
                suiteId: $suite->id,
                name: 'Unclassified Test Case',
                testInputs: ['query' => 'Synthetic text'],
                expectedAssertions: ['contains_text' => 'text'],
                isSynthetic: false,
                isDeidentified: false,
            );
            $this->fail('Expected exception for unclassified eval case');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('must be exactly one', $e->getMessage());
        }

        // 2. Rejects real client ID reference
        try {
            $action->execute(
                actor: $this->user,
                organization: $this->organization,
                suiteId: $suite->id,
                name: 'Real Client Reference Case',
                testInputs: ['client_id' => $client->id, 'query' => 'Text'],
                expectedAssertions: ['contains_text' => 'text'],
                isSynthetic: true,
            );
            $this->fail('Expected exception for real client ID in eval case');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Production reference', $e->getMessage());
        }

        try {
            $action->execute(
                actor: $this->user,
                organization: $this->organization,
                suiteId: $suite->id,
                name: 'Nested Patient Reference Case',
                testInputs: ['fixture' => ['patient' => ['client_id' => $client->id]]],
                expectedAssertions: [],
                isSynthetic: true,
            );
            $this->fail('Expected nested production reference to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Production reference', $e->getMessage());
        }

        // 3. Rejects real email pattern
        try {
            $action->execute(
                actor: $this->user,
                organization: $this->organization,
                suiteId: $suite->id,
                name: 'Real Email Case',
                testInputs: ['patient_email' => 'real.patient@gmail.com'],
                expectedAssertions: ['contains_text' => 'text'],
                isSynthetic: true,
            );
            $this->fail('Expected exception for real email in eval case');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Real email addresses', $e->getMessage());
        }

        // 4. Rejects direct import of protected AI run traces
        try {
            $action->execute(
                actor: $this->user,
                organization: $this->organization,
                suiteId: $suite->id,
                name: 'Protected Trace Case',
                testInputs: ['encrypted_output_text' => 'raw_cipher_text'],
                expectedAssertions: ['contains_text' => 'text'],
                isSynthetic: true,
            );
            $this->fail('Expected exception for protected trace import in eval case');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Production reference', $e->getMessage());
        }
    }

    public function test_eval_case_creation_requires_synthetic_fixtures_and_validates_all_payload_sections(): void
    {
        $suite = AiEvalSuite::create([
            'organization_id' => $this->organization->id,
            'key' => 'strict_eval_payload_suite',
            'name' => 'Strict eval payload suite',
            'capability' => AiCapability::PostureAnalysis,
        ]);
        $action = app(CreateEvalCase::class);

        try {
            $action->execute(
                actor: $this->user,
                organization: $this->organization,
                suiteId: $suite->id,
                name: 'Inline deidentified fixture',
                testInputs: ['query' => 'neutral fixture text'],
                expectedAssertions: [],
                isSynthetic: false,
                isDeidentified: true,
            );
            $this->fail('Raw inline deidentified fixtures must not be accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('synthetic fixtures only', $exception->getMessage());
        }

        try {
            $action->execute(
                actor: $this->user,
                organization: $this->organization,
                suiteId: $suite->id,
                name: 'Prohibited assertion reference',
                testInputs: ['query' => 'synthetic fixture text'],
                expectedAssertions: ['nested' => ['medical_session_id' => 44]],
                isSynthetic: true,
                isDeidentified: false,
            );
            $this->fail('Expected protected references in assertions to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Production reference', $exception->getMessage());
        }

        try {
            $action->execute(
                actor: $this->user,
                organization: $this->organization,
                suiteId: $suite->id,
                name: 'Prohibited output schema reference',
                testInputs: ['query' => 'synthetic fixture text'],
                expectedAssertions: [],
                expectedOutputSchema: ['properties' => ['client_id' => ['type' => 'integer']]],
                isSynthetic: true,
                isDeidentified: false,
            );
            $this->fail('Expected protected references in output schema to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Production reference', $exception->getMessage());
        }

        $case = $action->execute(
            actor: $this->user,
            organization: $this->organization,
            suiteId: $suite->id,
            name: 'Synthetic fixture',
            testInputs: ['query' => 'synthetic fixture text', 'notes' => 'fictional notes'],
            expectedAssertions: ['contains_text' => 'fixture'],
            expectedOutputSchema: ['type' => 'object', 'properties' => ['answer' => ['type' => 'string']]],
            isSynthetic: true,
            isDeidentified: false,
        );

        $this->assertTrue($case->is_synthetic);
        $this->assertFalse($case->is_deidentified);
        $this->assertSame('fictional notes', $case->test_inputs['notes']);
    }

    public function test_update_eval_case_revalidates_privacy_and_rejects_patient_references(): void
    {
        $suite = AiEvalSuite::create([
            'organization_id' => $this->organization->id,
            'key' => 'update_privacy_suite',
            'name' => 'Сьют проверки обновления',
            'capability' => AiCapability::PostureAnalysis,
        ]);

        $client = new Client(['full_name' => 'Пациент Проверка']);
        $client->organization_id = max(0, (int) $this->organization->id);
        $client->save();

        $case = AiEvalCase::create([
            'organization_id' => $this->organization->id,
            'eval_suite_id' => $suite->id,
            'name' => 'Валидный кейс',
            'is_synthetic' => true,
            'is_deidentified' => false,
            'test_inputs' => ['query' => 'Синтетический запрос'],
            'expected_assertions' => ['contains_text' => 'тест'],
            'is_active' => true,
        ]);

        /** @var UpdateEvalCase $action */
        $action = app(UpdateEvalCase::class);

        // Update with forbidden client reference
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Production reference');

        $action->execute($this->user, $case, [
            'name' => 'Попытка обновления с реальным пациентом',
            'test_inputs' => ['client_id' => $client->id],
            'is_synthetic' => true,
            'is_deidentified' => false,
        ]);
    }

    public function test_evaluation_revalidates_nested_privacy_before_execution_after_direct_database_write(): void
    {
        $prompt = AiPrompt::create([
            'organization_id' => $this->organization->id,
            'key' => 'legacy_privacy_prompt',
            'name' => 'Legacy privacy prompt',
            'capability' => AiCapability::PostureAnalysis,
        ]);
        $version = AiPromptVersion::create([
            'organization_id' => $this->organization->id,
            'prompt_id' => $prompt->id,
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'Evaluate posture.',
            'user_prompt_template' => '{{query}}',
            'activated_at' => Carbon::now(),
        ]);
        $prompt->update(['active_version_id' => $version->id]);
        $suite = AiEvalSuite::create([
            'organization_id' => $this->organization->id,
            'key' => 'legacy_privacy_suite',
            'name' => 'Legacy privacy suite',
            'capability' => AiCapability::PostureAnalysis,
            'prompt_id' => $prompt->id,
        ]);
        AiEvalCase::create([
            'organization_id' => $this->organization->id,
            'eval_suite_id' => $suite->id,
            'name' => 'Legacy nested reference',
            'is_synthetic' => true,
            'is_deidentified' => false,
            'test_inputs' => ['fixture' => ['patient' => ['medical_session_id' => 42]]],
            'expected_assertions' => [],
            'is_active' => true,
        ]);

        try {
            app(RunEvaluationSuite::class)->handle(
                actor: $this->user,
                evalSuiteId: $suite->id,
                promptVersionId: $version->id,
                modelReleaseId: AiModelRelease::query()->where('organization_id', $this->organization->id)->value('id'),
            );
            $this->fail('Expected legacy nested production reference to be rejected before execution.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Production reference', $e->getMessage());
        }

        AiEvalCase::query()->where('eval_suite_id', $suite->id)->delete();
        AiEvalCase::create([
            'organization_id' => $this->organization->id,
            'eval_suite_id' => $suite->id,
            'name' => 'Legacy assertion reference',
            'is_synthetic' => true,
            'is_deidentified' => false,
            'test_inputs' => ['query' => 'synthetic query'],
            'expected_assertions' => ['nested' => ['ai_run_payload_id' => 42]],
            'is_active' => true,
        ]);

        try {
            app(RunEvaluationSuite::class)->handle(
                actor: $this->user,
                evalSuiteId: $suite->id,
                promptVersionId: $version->id,
                modelReleaseId: AiModelRelease::query()->where('organization_id', $this->organization->id)->value('id'),
            );
            $this->fail('Expected legacy assertion reference to be rejected before execution.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Production reference', $e->getMessage());
        }

        AiEvalCase::query()->where('eval_suite_id', $suite->id)->delete();
        AiEvalCase::create([
            'organization_id' => $this->organization->id,
            'eval_suite_id' => $suite->id,
            'name' => 'Legacy output schema reference',
            'is_synthetic' => true,
            'is_deidentified' => false,
            'test_inputs' => ['query' => 'synthetic query'],
            'expected_assertions' => [],
            'expected_output_schema' => ['properties' => ['protected_trace_id' => ['type' => 'string']]],
            'is_active' => true,
        ]);

        try {
            app(RunEvaluationSuite::class)->handle(
                actor: $this->user,
                evalSuiteId: $suite->id,
                promptVersionId: $version->id,
                modelReleaseId: AiModelRelease::query()->where('organization_id', $this->organization->id)->value('id'),
            );
            $this->fail('Expected legacy output schema reference to be rejected before execution.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Production reference', $e->getMessage());
        }

        $this->assertSame(0, AiRun::query()->where('organization_id', $this->organization->id)->count());
    }

    public function test_evaluation_revalidates_case_classification_before_execution(): void
    {
        $prompt = AiPrompt::create([
            'organization_id' => $this->organization->id,
            'key' => 'legacy_classification_prompt',
            'name' => 'Legacy classification prompt',
            'capability' => AiCapability::PostureAnalysis,
        ]);
        $version = AiPromptVersion::create([
            'organization_id' => $this->organization->id,
            'prompt_id' => $prompt->id,
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'Evaluate posture.',
            'user_prompt_template' => '{{query}}',
            'activated_at' => Carbon::now(),
        ]);
        $suite = AiEvalSuite::create([
            'organization_id' => $this->organization->id,
            'key' => 'legacy_classification_suite',
            'name' => 'Legacy classification suite',
            'capability' => AiCapability::PostureAnalysis,
            'prompt_id' => $prompt->id,
        ]);
        AiEvalCase::create([
            'organization_id' => $this->organization->id,
            'eval_suite_id' => $suite->id,
            'name' => 'Unclassified legacy case',
            'is_synthetic' => false,
            'is_deidentified' => false,
            'test_inputs' => ['query' => 'synthetic query'],
            'expected_assertions' => [],
            'is_active' => true,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be exactly one');

        app(RunEvaluationSuite::class)->handle(
            actor: $this->user,
            evalSuiteId: $suite->id,
            promptVersionId: $version->id,
            modelReleaseId: $this->modelConfiguration->active_release_id,
        );
    }

    public function test_eval_suite_composite_prompt_foreign_key_rejects_cross_organization_prompt(): void
    {
        $otherOrganization = Organization::factory()->create();
        $otherPrompt = AiPrompt::create([
            'organization_id' => $otherOrganization->id,
            'key' => 'other_org_prompt',
            'name' => 'Other organization prompt',
            'capability' => AiCapability::PostureAnalysis,
        ]);

        $this->expectException(QueryException::class);

        AiEvalSuite::create([
            'organization_id' => $this->organization->id,
            'key' => 'cross_org_prompt_suite',
            'name' => 'Cross organization prompt suite',
            'capability' => AiCapability::PostureAnalysis,
            'prompt_id' => $otherPrompt->id,
        ]);
    }

    public function test_evaluation_pins_release_a_when_release_b_is_active(): void
    {
        DynamicWorkflowAgent::fake([
            '{"symmetry_observations": [], "posture_type": "normal"}',
            '{"symmetry_observations": [], "posture_type": "normal"}',
        ]);

        $prompt = AiPrompt::create([
            'organization_id' => $this->organization->id,
            'key' => 'pinned_eval_prompt',
            'name' => 'Pinned eval prompt',
            'capability' => AiCapability::PostureAnalysis,
        ]);
        $version = AiPromptVersion::create([
            'organization_id' => $this->organization->id,
            'prompt_id' => $prompt->id,
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'Evaluate posture.',
            'user_prompt_template' => 'Input: {{query}}',
            'activated_at' => Carbon::now(),
        ]);
        $prompt->update(['active_version_id' => $version->id]);
        $suite = AiEvalSuite::create([
            'organization_id' => $this->organization->id,
            'key' => 'pinned_release_suite',
            'name' => 'Pinned release suite',
            'capability' => AiCapability::PostureAnalysis,
            'prompt_id' => $prompt->id,
        ]);
        AiEvalCase::create([
            'organization_id' => $this->organization->id,
            'eval_suite_id' => $suite->id,
            'name' => 'Pinned case one',
            'is_synthetic' => true,
            'is_deidentified' => false,
            'test_inputs' => ['query' => 'synthetic one'],
            'expected_assertions' => [],
            'is_active' => true,
        ]);
        AiEvalCase::create([
            'organization_id' => $this->organization->id,
            'eval_suite_id' => $suite->id,
            'name' => 'Pinned case two',
            'is_synthetic' => true,
            'is_deidentified' => false,
            'test_inputs' => ['query' => 'synthetic two'],
            'expected_assertions' => [],
            'is_active' => true,
        ]);

        $releaseA = AiModelRelease::query()
            ->where('organization_id', $this->organization->id)
            ->where('model_config_id', $this->modelConfiguration->id)
            ->firstOrFail();
        $releaseB = app(CreateAndActivateModelRelease::class)->handle($this->user, $this->modelConfiguration, [
            'model_name' => 'gpt-4o',
            'display_name' => 'GPT-4o',
            'capabilities' => [AiCapability::PostureAnalysis->value],
            'input_cost_per_million' => 250,
            'output_cost_per_million' => 1000,
        ]);

        $this->assertSame('retired', $releaseA->refresh()->status);
        $this->assertSame('active', $releaseB->status);

        $evalRun = app(RunEvaluationSuite::class)->handle(
            actor: $this->user,
            evalSuiteId: $suite->id,
            promptVersionId: $version->id,
            modelReleaseId: $releaseA->id,
        );

        $this->assertSame($releaseA->id, $evalRun->model_release_id);
        $this->assertSame('openai', $evalRun->provider);
        $this->assertSame('gpt-4o-mini', $evalRun->model);
        $runs = AiRun::query()->where('organization_id', $this->organization->id)->get();
        $this->assertCount(2, $runs);
        $this->assertSame([$releaseA->id, $releaseA->id], $runs->pluck('model_release_id')->sort()->values()->all());
        $this->assertSame(['openai', 'openai'], $runs->pluck('actual_provider')->all());
        $this->assertSame(['gpt-4o-mini', 'gpt-4o-mini'], $runs->pluck('actual_model')->all());
        $this->assertSame($releaseA->id, $evalRun->results_payload['cases'][0]['model_release_id']);
        $this->assertSame($releaseA->id, $evalRun->results_payload['cases'][1]['model_release_id']);
    }
}
