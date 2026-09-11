<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Surveys\Application\CompleteSurveyAttempt;
use App\Modules\Surveys\Application\InstallPlatformSurveyCatalog;
use App\Modules\Surveys\Application\PlatformSurveyCatalog;
use App\Modules\Surveys\Application\StartSurveyAttempt;
use App\Modules\Surveys\Domain\Enums\SurveyVersionStatus;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use App\Modules\Surveys\Domain\Models\SurveyComparison;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use App\Modules\Surveys\Domain\Models\SurveyReport;
use App\Modules\Surveys\Domain\Models\SurveyVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlatformHealthExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_catalog_contains_two_explicitly_draft_content_families(): void
    {
        $catalog = app(InstallPlatformSurveyCatalog::class)->catalog();

        self::assertCount(2, $catalog);
        self::assertSame('platform_default', $catalog[0]['source']);
        self::assertSame('draft', $catalog[0]['approval_status']);
        self::assertSame('platform_extended_symptom_questionnaire', $catalog[1]['methodology']);
        self::assertSame('platform_default', $catalog[1]['source']);
        self::assertSame('draft', $catalog[1]['approval_status']);
        self::assertNotSame('official_msq', $catalog[1]['methodology']);
    }

    public function test_seeded_nine_systems_has_forty_five_questions_and_neutral_scoring(): void
    {
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);

        app(InstallPlatformSurveyCatalog::class)->handle($organization);

        $definition = SurveyDefinition::query()->where('organization_id', $organization->getKey())->where('definition_key', 'platform_health_9_systems')->firstOrFail();
        $version = $definition->activeVersion()->firstOrFail();
        $questions = collect($version->definition['sections'])->flatMap(static fn (array $section): array => $section['questions'])->values();

        self::assertCount(9, $version->definition['sections']);
        self::assertCount(45, $questions);
        self::assertSame('platform_default', $version->source);
        self::assertSame('draft', $version->approval_status);
        self::assertSame('published', $version->status->value);
        self::assertCount(9, $version->scoring['metrics']);
        self::assertSame(['never', 'rarely', 'sometimes', 'often', 'almost_always'], array_column($questions[0]['options'], 'value'));
        self::assertSame(['never' => 0, 'rarely' => 1, 'sometimes' => 2, 'often' => 3, 'almost_always' => 4], $version->scoring['answer_scale']);
    }

    public function test_seed_is_idempotent_and_new_version_does_not_rewrite_completed_attempt(): void
    {
        [$organization, $client] = $this->fixture();
        $installer = app(InstallPlatformSurveyCatalog::class);
        $installer->handle($organization);
        $installer->handle($organization);

        self::assertSame(2, SurveyDefinition::query()->where('organization_id', $organization->getKey())->count());

        $definition = SurveyDefinition::query()->where('organization_id', $organization->getKey())->where('definition_key', 'platform_health_9_systems')->firstOrFail();
        $attempt = app(StartSurveyAttempt::class)->handle($client, $definition);
        $answers = $this->answers($attempt);
        $completed = app(CompleteSurveyAttempt::class)->handle($client, $attempt, $answers);

        $oldQuestion = $completed->definition_snapshot['sections'][0]['questions'][0]['label'];
        $currentVersion = $definition->activeVersion()->firstOrFail();
        $staleDefinition = $currentVersion->definition;
        $staleDefinition['sections'][0]['questions'][0]['label']['ru'] .= ' (stale)';
        $staleVersion = SurveyVersion::query()->create([
            'organization_id' => $organization->getKey(),
            'survey_definition_id' => $definition->getKey(),
            'version' => $definition->versions()->max('version') + 1,
            'status' => SurveyVersionStatus::Published,
            'title' => $currentVersion->title,
            'title_en' => $currentVersion->title_en,
            'description' => $currentVersion->description,
            'description_en' => $currentVersion->description_en,
            'definition' => $staleDefinition,
            'scoring' => $currentVersion->scoring,
            'metric_schema_key' => $currentVersion->metric_schema_key,
            'source' => 'platform_default',
            'approval_status' => 'draft',
            'methodology' => $currentVersion->methodology,
            'published_at' => now(),
        ]);
        $definition->forceFill(['active_version_id' => $staleVersion->getKey()])->save();
        $installer->handle($organization);

        self::assertSame($oldQuestion, $completed->fresh()->definition_snapshot['sections'][0]['questions'][0]['label']);
        self::assertGreaterThanOrEqual(2, $definition->fresh()->versions()->count());
    }

    public function test_platform_installer_does_not_replace_an_approved_published_version(): void
    {
        [$organization] = $this->fixture();
        $installer = app(InstallPlatformSurveyCatalog::class);
        $installer->handle($organization);
        $definition = SurveyDefinition::query()
            ->where('organization_id', $organization->getKey())
            ->where('definition_key', PlatformSurveyCatalog::HEALTH_KEY)
            ->firstOrFail();
        $current = $definition->activeVersion()->firstOrFail();
        $approved = SurveyVersion::query()->create([
            'organization_id' => $organization->getKey(),
            'survey_definition_id' => $definition->getKey(),
            'version' => $definition->versions()->max('version') + 1,
            'status' => SurveyVersionStatus::Published,
            'title' => $current->title,
            'title_en' => $current->title_en,
            'description' => $current->description,
            'description_en' => $current->description_en,
            'definition' => $current->definition,
            'scoring' => $current->scoring,
            'metric_schema_key' => $current->metric_schema_key,
            'source' => 'chuklov_approved',
            'approval_status' => 'approved',
            'methodology' => 'chuklov_approved_content',
            'published_at' => now(),
        ]);
        $definition->forceFill(['active_version_id' => $approved->getKey()])->save();

        $installer->handle($organization);

        self::assertSame($approved->getKey(), $definition->fresh()->active_version_id);
        self::assertSame(2, $definition->fresh()->versions()->count());
        self::assertSame('chuklov_approved', $approved->fresh()->source);
    }

    public function test_completion_materializes_friendly_report_top_three_road_map_and_repeat_dynamics(): void
    {
        [$organization, $client] = $this->fixture();
        app(InstallPlatformSurveyCatalog::class)->handle($organization);
        $definition = SurveyDefinition::query()->where('organization_id', $organization->getKey())->where('definition_key', 'platform_health_9_systems')->firstOrFail();

        $first = app(StartSurveyAttempt::class)->handle($client, $definition);
        $firstCompleted = app(CompleteSurveyAttempt::class)->handle($client, $first, $this->answers($first, ['digestive' => 'almost_always', 'sleep' => 'often', 'stress' => 'often']));
        $second = app(StartSurveyAttempt::class)->handle($client, $definition);
        $secondCompleted = app(CompleteSurveyAttempt::class)->handle($client, $second, $this->answers($second, ['digestive' => 'sometimes', 'sleep' => 'rarely', 'stress' => 'sometimes']));

        $report = SurveyReport::query()->where('survey_attempt_id', $secondCompleted->getKey())->firstOrFail();
        $comparison = SurveyComparison::query()->where('current_attempt_id', $secondCompleted->getKey())->firstOrFail();

        self::assertNotSame('', $report->report_snapshot['summary']['short'] ?? '');
        self::assertCount(3, $report->report_snapshot['attention_areas']);
        self::assertNotEmpty($report->report_snapshot['attention_areas'][0]['reason']);
        self::assertNotEmpty($report->report_snapshot['safe_steps']);
        self::assertNotEmpty($report->report_snapshot['specialist_questions']);
        self::assertNotEmpty($report->report_snapshot['road_map']['items']);
        self::assertSame('Открыть чат', $report->report_snapshot['ctas']['companion']['ru']);
        self::assertSame('improved', $comparison->status);
        self::assertSame('normalized_score', $comparison->comparison_snapshot['metrics']['digestive']['basis']);
        self::assertSame($firstCompleted->survey_version_id, $secondCompleted->survey_version_id);
    }

    public function test_low_burden_report_does_not_claim_that_zero_answers_are_symptoms(): void
    {
        [$organization, $client] = $this->fixture();
        app(InstallPlatformSurveyCatalog::class)->handle($organization);
        $definition = SurveyDefinition::query()
            ->where('organization_id', $organization->getKey())
            ->where('definition_key', PlatformSurveyCatalog::HEALTH_KEY)
            ->firstOrFail();
        $attempt = app(StartSurveyAttempt::class)->handle($client, $definition);
        $answers = [];
        foreach (collect($attempt->definition_snapshot['sections'])->flatMap(static fn (array $section): array => $section['questions']) as $question) {
            $answers[$question['key']] = 'never';
        }

        app(CompleteSurveyAttempt::class)->handle($client, $attempt, $answers);
        $report = SurveyReport::query()->where('survey_attempt_id', $attempt->getKey())->firstOrFail();

        self::assertSame('Низкая симптомная нагрузка', $report->report_snapshot['attention_areas'][0]['status']['ru']);
        self::assertSame('По этим ответам выраженных жалоб в этой зоне не отмечено.', $report->report_snapshot['attention_areas'][0]['reason']['ru']);
        self::assertSame([], $report->report_snapshot['attention_areas'][0]['evidence']);
    }

    /** @return array{Organization, Client} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $client = Client::factory()->forOrganization($organization)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        return [$organization, $client];
    }

    /** @return array<string, string> */
    private function answers(SurveyAttempt $attempt, array $overrides = []): array
    {
        $answers = [];
        foreach (collect($attempt->definition_snapshot['sections'])->flatMap(static fn (array $section): array => $section['questions']) as $question) {
            $domain = (string) explode('_', (string) $question['key'])[0];
            $answers[$question['key']] = $overrides[$domain] ?? 'rarely';
        }

        return $answers;
    }
}
