<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AI\Application\Actions\ActivatePromptVersion;
use App\Modules\AI\Application\Actions\CreatePromptDraft;
use App\Modules\AI\Application\Actions\MaterializeSourceBackedEvaluations;
use App\Modules\AI\Domain\Enums\PromptVersionStatus;
use App\Modules\AI\Domain\Models\AiEvalCase;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SourceBackedMaterializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_backed_manifest_materializes_idempotently_for_one_organization(): void
    {
        [$organization, $admin] = $this->organizationFixture();

        $first = app(MaterializeSourceBackedEvaluations::class)->handle($admin);

        self::assertSame([
            'prompts_created' => 4,
            'prompt_versions_created' => 4,
            'prompts_activated' => 0,
            'suites_created' => 4,
            'cases_created' => 58,
        ], $first);
        self::assertSame(4, AiPrompt::query()->where('organization_id', $organization->getKey())->count());
        self::assertSame(4, AiPromptVersion::query()->where('organization_id', $organization->getKey())->count());
        self::assertSame(4, AiEvalSuite::query()->where('organization_id', $organization->getKey())->count());
        self::assertSame(58, AiEvalCase::query()->where('organization_id', $organization->getKey())->count());
        self::assertSame(
            [
                'absent_measurement_remains_unknown',
                'contradictory_phrases_are_not_resolved_by_guess',
                'does_not_invent_root_involvement',
                'extracts_multiple_findings',
                'noisy_report_preserves_uncertainty',
                'ordinary_finding_not_exaggerated',
                'preserves_explicit_measurement',
                'retains_critical_source_flag',
            ],
            AiEvalCase::query()
                ->where('organization_id', $organization->getKey())
                ->where('eval_suite_id', AiEvalSuite::query()->where('key', 'source_agent_1_document_extraction')->value('id'))
                ->orderBy('source_key')
                ->pluck('source_key')
                ->all(),
        );
        $second = app(MaterializeSourceBackedEvaluations::class)->handle($admin);

        self::assertSame([
            'prompts_created' => 0,
            'prompt_versions_created' => 0,
            'prompts_activated' => 0,
            'suites_created' => 0,
            'cases_created' => 0,
        ], $second);
        self::assertSame(4, AiPromptVersion::query()->where('organization_id', $organization->getKey())->count());
        self::assertSame(58, AiEvalCase::query()->where('organization_id', $organization->getKey())->count());
    }

    public function test_source_backed_evaluation_copy_survives_re_materialization(): void
    {
        [$organization, $admin] = $this->organizationFixture();
        app(MaterializeSourceBackedEvaluations::class)->handle($admin);

        $suite = AiEvalSuite::query()
            ->where('organization_id', $organization->getKey())
            ->where('key', 'source_agent_1_document_extraction')
            ->firstOrFail();
        $case = AiEvalCase::query()
            ->where('organization_id', $organization->getKey())
            ->where('eval_suite_id', $suite->getKey())
            ->firstOrFail();

        $suite->forceFill([
            'name' => 'Моя проверка',
            'description' => 'Моё описание проверки.',
        ])->save();
        $case->forceFill([
            'name' => 'Мой сценарий',
            'test_inputs' => ['query' => 'Мой ввод'],
            'expected_assertions' => ['custom_assertion'],
            'expected_output_schema' => ['type' => 'custom'],
            'is_synthetic' => false,
            'is_deidentified' => true,
            'is_active' => false,
        ])->save();

        $summary = app(MaterializeSourceBackedEvaluations::class)->handle($admin);

        self::assertSame(0, $summary['suites_created']);
        self::assertSame(0, $summary['cases_created']);
        self::assertSame('Моя проверка', $suite->fresh()->name);
        self::assertSame('Моё описание проверки.', $suite->fresh()->description);
        self::assertSame('Мой сценарий', $case->fresh()->name);
        self::assertSame(['query' => 'Мой ввод'], $case->fresh()->test_inputs);
        self::assertSame(['custom_assertion'], $case->fresh()->expected_assertions);
        self::assertSame(['type' => 'custom'], $case->fresh()->expected_output_schema);
        self::assertFalse($case->fresh()->is_active);
    }

    public function test_source_backed_materialization_does_not_replace_a_custom_active_prompt(): void
    {
        [$organization, $admin] = $this->organizationFixture();
        app(MaterializeSourceBackedEvaluations::class)->handle($admin, true);

        $prompt = AiPrompt::query()
            ->where('organization_id', $organization->getKey())
            ->where('key', 'client_companion_source_draft')
            ->firstOrFail();
        $custom = app(CreatePromptDraft::class)->handle($admin, $prompt->getKey(), [
            'system_prompt' => 'Моя активная инструкция.',
            'user_prompt_template' => 'Ответьте на запрос: {{ query }}',
        ]);
        app(ActivatePromptVersion::class)->handle($admin, $custom->getKey());

        app(MaterializeSourceBackedEvaluations::class)->handle($admin);

        self::assertSame($custom->getKey(), $prompt->fresh()->active_version_id);
        self::assertSame(PromptVersionStatus::Active, $custom->fresh()->status);
    }

    public function test_source_backed_prompt_activation_is_explicit_and_tenant_scoped(): void
    {
        [$organization, $admin] = $this->organizationFixture();
        $foreignOrganization = Organization::factory()->create();
        User::factory()->forOrganization($foreignOrganization, OrganizationRole::Administrator)->create();

        $summary = app(MaterializeSourceBackedEvaluations::class)->handle($admin, true);

        self::assertSame(4, $summary['prompts_activated']);
        self::assertSame(4, AiPrompt::query()->where('organization_id', $organization->getKey())->whereNotNull('active_version_id')->count());
        self::assertSame(0, AiPrompt::query()->where('organization_id', $foreignOrganization->getKey())->count());
        self::assertSame(4, AiPromptVersion::query()->where('organization_id', $organization->getKey())->where('status', PromptVersionStatus::Active->value)->count());

        $second = app(MaterializeSourceBackedEvaluations::class)->handle($admin, true);

        self::assertSame(0, $second['prompt_versions_created']);
        self::assertSame(0, $second['prompts_activated']);
    }

    /** @return array{0: Organization, 1: User} */
    private function organizationFixture(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }
}
