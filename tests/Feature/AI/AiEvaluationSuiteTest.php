<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\CreateEvalCase;
use App\Modules\AI\Application\Actions\RunEvaluationSuite;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Models\AiEvalCase;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Infrastructure\Engine\DynamicWorkflowAgent;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class AiEvaluationSuiteTest extends TestCase
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

        $this->setupConfiguredModel(AiCapability::PostureAnalysis);
    }

    private function setupConfiguredModel(AiCapability $capability, string $providerName = 'openai', string $modelName = 'gpt-4o-mini'): void
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
            'credential_id' => $credential->id,
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
            'test_inputs' => ['query' => 'Оценка симметрии'],
            'expected_assertions' => ['contains_text' => 'симметрия'],
            'is_active' => true,
        ]);

        AiEvalCase::create([
            'organization_id' => $this->organization->id,
            'eval_suite_id' => $suite->id,
            'name' => 'Кейс 2: Сколиоз',
            'is_synthetic' => true,
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
            $this->assertStringContainsString('must be explicitly classified', $e->getMessage());
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
            $this->assertStringContainsString('Real production client IDs are prohibited', $e->getMessage());
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
            $this->assertStringContainsString('Real email addresses detected', $e->getMessage());
        }
    }
}
