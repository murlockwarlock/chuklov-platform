<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\CreateEvalCase;
use App\Modules\AI\Application\Actions\RunEvaluationSuite;
use App\Modules\AI\Application\Actions\UpdateEvalCase;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Models\AiEvalCase;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use App\Policies\AiPromptPolicy;
use App\Policies\AiRunPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organizationA;

    private Organization $organizationB;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationA = Organization::create([
            'name' => 'Clinic A',
            'slug' => 'clinic-a',
        ]);

        $this->organizationB = Organization::create([
            'name' => 'Clinic B',
            'slug' => 'clinic-b',
        ]);

        $this->userA = User::factory()->forOrganization($this->organizationA, OrganizationRole::Administrator)->create();

        config()->set('tenancy.default_organization_id', $this->organizationA->id);
        app(OrganizationContext::class)->set($this->organizationA);
    }

    public function test_user_a_cannot_view_ai_run_belonging_to_organization_b(): void
    {
        $runB = AiRun::create([
            'organization_id' => $this->organizationB->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'org_b_run',
            'status' => AiRunStatus::Succeeded,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);

        $policy = app(AiRunPolicy::class);

        $this->assertFalse($policy->view($this->userA, $runB));
        $this->assertFalse($policy->viewTrace($this->userA, $runB));
        $this->assertFalse($policy->review($this->userA, $runB));
    }

    public function test_user_a_cannot_view_or_modify_prompt_belonging_to_organization_b(): void
    {
        $promptB = AiPrompt::create([
            'organization_id' => $this->organizationB->id,
            'key' => 'org_b_prompt',
            'name' => 'Промпт клиники Б',
            'capability' => AiCapability::ClientCompanion,
        ]);

        $policy = app(AiPromptPolicy::class);

        $this->assertFalse($policy->view($this->userA, $promptB));
        $this->assertFalse($policy->update($this->userA, $promptB));
        $this->assertFalse($policy->delete($this->userA, $promptB));
    }

    public function test_user_a_cannot_run_evaluation_suite_belonging_to_organization_b(): void
    {
        $suiteB = AiEvalSuite::create([
            'organization_id' => $this->organizationB->id,
            'key' => 'suite_b',
            'name' => 'Тесты Б',
            'capability' => AiCapability::ClientCompanion,
        ]);

        $promptB = AiPrompt::create([
            'organization_id' => $this->organizationB->id,
            'key' => 'prompt_b',
            'name' => 'Промпт Б',
            'capability' => AiCapability::ClientCompanion,
        ]);

        $versionB = AiPromptVersion::create([
            'organization_id' => $this->organizationB->id,
            'prompt_id' => $promptB->id,
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'Инструкция',
            'user_prompt_template' => '{{query}}',
        ]);

        $runner = app(RunEvaluationSuite::class);

        $this->expectException(\InvalidArgumentException::class);
        $runner->handle(
            actor: $this->userA,
            evalSuiteId: $suiteB->id,
            promptVersionId: $versionB->id,
        );
    }

    public function test_user_a_cannot_create_or_update_evaluation_case_in_organization_b(): void
    {
        $suiteB = AiEvalSuite::create([
            'organization_id' => $this->organizationB->id,
            'key' => 'case_suite_b',
            'name' => 'Примеры Б',
            'capability' => AiCapability::ClientCompanion,
        ]);
        $caseB = AiEvalCase::create([
            'organization_id' => $this->organizationB->id,
            'eval_suite_id' => $suiteB->id,
            'name' => 'Пример Б',
            'is_synthetic' => true,
            'is_deidentified' => false,
            'test_inputs' => ['query' => 'synthetic'],
            'expected_assertions' => [],
            'is_active' => true,
        ]);

        $creationException = null;

        try {
            app(CreateEvalCase::class)->execute(
                actor: $this->userA,
                organization: $this->organizationB,
                suiteId: $suiteB->id,
                name: 'Чужой пример',
                testInputs: ['query' => 'synthetic'],
                expectedAssertions: [],
                isSynthetic: true,
                isDeidentified: false,
            );
            self::fail('Cross-organization evaluation case creation must be denied.');
        } catch (AuthorizationException $exception) {
            $creationException = $exception;
        }

        $updateException = null;

        try {
            app(UpdateEvalCase::class)->execute($this->userA, $caseB, [
                'is_synthetic' => true,
                'is_deidentified' => false,
            ]);
            self::fail('Cross-organization evaluation case update must be denied.');
        } catch (AuthorizationException $exception) {
            $updateException = $exception;
        }

        self::assertInstanceOf(AuthorizationException::class, $creationException);
        self::assertInstanceOf(AuthorizationException::class, $updateException);
    }

    public function test_cross_organization_client_reference_cannot_be_persisted_in_ai_run(): void
    {
        $clientB = new Client(['full_name' => 'Client Org B']);
        $clientB->organization_id = max(0, (int) $this->organizationB->id);
        $clientB->save();

        $attributes = [
            'organization_id' => $this->organizationA->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'cross_client_test',
            'client_id' => $clientB->id,
            'status' => AiRunStatus::Queued,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ];

        if (config('database.default') === 'pgsql') {
            $this->expectException(QueryException::class);
            AiRun::create($attributes);
        } else {
            try {
                $run = AiRun::create($attributes);
                $this->assertSame($clientB->id, $run->client_id);
            } catch (QueryException $e) {
                $this->assertStringContainsString('foreign key constraint', strtolower($e->getMessage()));
            }
        }
    }

    public function test_cross_organization_credential_cannot_be_bound_to_ai_provider_configuration(): void
    {
        $credentialB = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'Org B Secret',
            'revision_id' => (string) Str::uuid(),
        ]);
        $credentialB->organization_id = max(0, (int) $this->organizationB->id);
        $credentialB->credentials = ['api_key' => 'sk-org-b'];
        $credentialB->save();

        $attributes = [
            'organization_id' => $this->organizationA->id,
            'provider_name' => 'openai_org_a',
            'display_name' => 'OpenAI Org A',
            'credential_id' => $credentialB->id,
        ];

        if (config('database.default') === 'pgsql') {
            $this->expectException(QueryException::class);
            AiProviderConfiguration::create($attributes);
        } else {
            try {
                $provider = AiProviderConfiguration::create($attributes);
                $this->assertSame($credentialB->id, $provider->credential_id);
            } catch (QueryException $e) {
                $this->assertStringContainsString('foreign key constraint', strtolower($e->getMessage()));
            }
        }
    }
}
