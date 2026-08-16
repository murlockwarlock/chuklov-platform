<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\RunEvaluationSuite;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Models\AiEvalCase;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Infrastructure\Engine\DynamicWorkflowAgent;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'test_inputs' => ['query' => 'Оценка симметрии'],
            'expected_assertions' => ['contains_text' => 'симметрия'],
            'is_active' => true,
        ]);

        AiEvalCase::create([
            'organization_id' => $this->organization->id,
            'eval_suite_id' => $suite->id,
            'name' => 'Кейс 2: Сколиоз',
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
}
