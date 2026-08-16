<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\RunEvaluationSuite;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Policies\AiPromptPolicy;
use App\Policies\AiRunPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
