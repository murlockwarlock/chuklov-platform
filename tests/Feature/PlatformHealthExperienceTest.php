<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Surveys\Application\CompleteSurveyAttempt;
use App\Modules\Surveys\Application\InstallPlatformSurveyCatalog;
use App\Modules\Surveys\Application\PlatformSurveyCatalog;
use App\Modules\Surveys\Application\PublishSurveyVersion;
use App\Modules\Surveys\Application\StartSurveyAttempt;
use App\Modules\Surveys\Domain\Enums\SurveyAttemptStatus;
use App\Modules\Surveys\Domain\Enums\SurveyVersionStatus;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use App\Modules\Surveys\Domain\Models\SurveyComparison;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use App\Modules\Surveys\Domain\Models\SurveyReport;
use App\Modules\Surveys\Domain\Models\SurveyVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;
use Mockery;
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

    public function test_seeded_nine_systems_stays_an_inactive_draft_until_approved(): void
    {
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);

        app(InstallPlatformSurveyCatalog::class)->handle($organization);

        $definition = SurveyDefinition::query()->where('organization_id', $organization->getKey())->where('definition_key', 'platform_health_9_systems')->firstOrFail();
        $version = $definition->versions()->sole();
        $questions = collect($version->definition['sections'])->flatMap(static fn (array $section): array => $section['questions'])->values();

        self::assertCount(9, $version->definition['sections']);
        self::assertCount(45, $questions);
        self::assertSame('platform_default', $version->source);
        self::assertSame('draft', $version->approval_status);
        self::assertSame(SurveyVersionStatus::Draft, $version->status);
        self::assertNull($definition->active_version_id);
        self::assertFalse($definition->is_available);
        self::assertCount(9, $version->scoring['metrics']);
        self::assertSame(['never', 'rarely', 'sometimes', 'often', 'almost_always'], array_column($questions[0]['options'], 'value'));
        self::assertSame(['never' => 0, 'rarely' => 1, 'sometimes' => 2, 'often' => 3, 'almost_always' => 4], $version->scoring['answer_scale']);
    }

    public function test_legacy_published_demo_is_deactivated_without_deleting_its_version(): void
    {
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);
        $catalog = app(InstallPlatformSurveyCatalog::class)->catalog()[0];
        $definition = SurveyDefinition::query()->create([
            'organization_id' => $organization->getKey(),
            'definition_key' => $catalog['definition_key'],
            'title' => $catalog['title'],
            'title_en' => $catalog['title_en'],
            'description' => $catalog['description'],
            'description_en' => $catalog['description_en'],
            'is_available' => true,
        ]);
        $version = SurveyVersion::query()->create([
            'organization_id' => $organization->getKey(),
            'survey_definition_id' => $definition->getKey(),
            'version' => 1,
            'status' => SurveyVersionStatus::Published,
            'title' => $catalog['title'],
            'title_en' => $catalog['title_en'],
            'description' => $catalog['description'],
            'description_en' => $catalog['description_en'],
            'definition' => $catalog['definition'],
            'scoring' => $catalog['scoring'],
            'metric_schema_key' => $catalog['metric_schema_key'],
            'source' => $catalog['source'],
            'approval_status' => $catalog['approval_status'],
            'methodology' => $catalog['methodology'],
            'published_at' => now(),
        ]);
        $definition->forceFill(['active_version_id' => $version->getKey()])->save();

        app(InstallPlatformSurveyCatalog::class)->handle($organization);

        self::assertSame(SurveyVersionStatus::Retired, $version->fresh()->status);
        self::assertNull($definition->fresh()->active_version_id);
        self::assertFalse($definition->fresh()->is_available);
        self::assertSame(1, $definition->versions()->count());
    }

    public function test_client_cannot_list_or_start_unapproved_content_and_old_attempts_return_a_clear_notice(): void
    {
        [$organization, $client] = $this->fixture();
        app(InstallPlatformSurveyCatalog::class)->handle($organization);
        $definition = SurveyDefinition::query()
            ->where('organization_id', $organization->getKey())
            ->where('definition_key', PlatformSurveyCatalog::HEALTH_KEY)
            ->firstOrFail();
        $version = $definition->versions()->sole();
        $version->forceFill([
            'status' => SurveyVersionStatus::Published,
            'published_at' => now(),
        ])->save();
        $definition->forceFill([
            'active_version_id' => $version->getKey(),
            'is_available' => true,
        ])->save();
        $attempt = SurveyAttempt::query()->create([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'survey_definition_id' => $definition->getKey(),
            'survey_version_id' => $version->getKey(),
            'status' => SurveyAttemptStatus::InProgress,
            'definition_snapshot' => $version->definition,
            'answers_snapshot' => [],
            'scoring_snapshot' => $version->scoring,
            'metric_schema_key' => $version->metric_schema_key,
            'started_at' => now(),
        ]);
        $this->withSession(['client_portal.client_id' => $client->getKey()]);
        Log::shouldReceive('warning')->once()->with('portal.surveys.start_denied', Mockery::on(fn (array $context): bool => $context['safe_error_code'] === 'content_not_approved'
            && $context['outcome'] === 'denied'
            && $context['operation'] === 'start_survey_attempt'
            && $context['organization_id'] === $organization->getKey()
            && $context['entity_id'] === $definition->getKey()
            && ! array_key_exists('definition_snapshot', $context)));
        Log::shouldReceive('warning')->once()->with('portal.surveys.attempt_access_denied', Mockery::on(fn (array $context): bool => $context['safe_error_code'] === 'attempt_unavailable'
            && $context['outcome'] === 'denied'
            && $context['operation'] === 'view_survey_attempt'
            && $context['organization_id'] === $organization->getKey()
            && $context['entity_id'] === $attempt->getKey()
            && ! array_key_exists('definition_snapshot', $context)));

        $this->get(route('portal.surveys.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Surveys')
                ->has('definitions', 0)
                ->has('attempts', 0));
        $this->post(route('portal.surveys.start', $definition->getKey()))
            ->assertRedirect(route('portal.surveys.index'))
            ->assertSessionHas('survey_start_unavailable', true);
        $this->get(route('portal.surveys.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('notices.startUnavailable', true));
        $this->get(route('portal.surveys.show', $attempt->getKey()))
            ->assertRedirect(route('portal.surveys.index'))
            ->assertSessionHas('survey_result_unavailable', true);
        $this->get(route('portal.surveys.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('notices.resultUnavailable', true));

        self::assertSame(1, SurveyAttempt::query()->where('client_id', $client->getKey())->count());
    }

    public function test_seed_is_idempotent_and_new_version_does_not_rewrite_completed_attempt(): void
    {
        [$organization, $client] = $this->fixture();
        $installer = app(InstallPlatformSurveyCatalog::class);
        $installer->handle($organization);
        $installer->handle($organization);

        self::assertSame(2, SurveyDefinition::query()->where('organization_id', $organization->getKey())->count());

        $definition = SurveyDefinition::query()->where('organization_id', $organization->getKey())->where('definition_key', 'platform_health_9_systems')->firstOrFail();
        $this->approvePlatformDraftForTest($organization, $definition);
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
        $current = $definition->versions()->latest('version')->firstOrFail();
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

    public function test_platform_installer_preserves_a_custom_published_version(): void
    {
        [$organization] = $this->fixture();
        $installer = app(InstallPlatformSurveyCatalog::class);
        $installer->handle($organization);
        $definition = SurveyDefinition::query()
            ->where('organization_id', $organization->getKey())
            ->where('definition_key', PlatformSurveyCatalog::HEALTH_KEY)
            ->firstOrFail();
        $current = $definition->versions()->latest('version')->firstOrFail();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $customScoring = $current->scoring;
        $customScoring['summary']['ru'] = 'Мой текст результата.';
        $customScoring['metrics'][0]['road_map']['ru'] = 'Мой следующий шаг.';
        $custom = SurveyVersion::query()->create([
            'organization_id' => $organization->getKey(),
            'survey_definition_id' => $definition->getKey(),
            'version' => $definition->versions()->max('version') + 1,
            'status' => SurveyVersionStatus::Published,
            'title' => 'Мой тест',
            'title_en' => 'My test',
            'description' => 'Моё описание.',
            'description_en' => 'My description.',
            'definition' => $current->definition,
            'scoring' => $customScoring,
            'metric_schema_key' => $current->metric_schema_key,
            'source_reference' => null,
            'source' => 'platform_default',
            'approval_status' => 'draft',
            'methodology' => $current->methodology,
            'created_by_user_id' => $admin->getKey(),
            'published_at' => now(),
        ]);
        $definition->forceFill(['active_version_id' => $custom->getKey()])->save();

        $installer->handle($organization);

        $definition->refresh();
        self::assertSame($custom->getKey(), $definition->active_version_id);
        self::assertSame(2, $definition->versions()->count());
        self::assertSame('Мой тест', $custom->fresh()->title);
        self::assertSame('Моё описание.', $custom->fresh()->description);
        self::assertSame('Мой текст результата.', $custom->fresh()->scoring['summary']['ru']);
        self::assertSame('Мой следующий шаг.', $custom->fresh()->scoring['metrics'][0]['road_map']['ru']);
    }

    public function test_platform_installer_does_not_activate_a_default_over_an_existing_custom_draft(): void
    {
        [$organization] = $this->fixture();
        $definition = SurveyDefinition::query()->create([
            'organization_id' => $organization->getKey(),
            'definition_key' => PlatformSurveyCatalog::HEALTH_KEY,
            'title' => 'Мой тест',
            'title_en' => 'My test',
            'description' => 'Моё описание.',
            'description_en' => 'My description.',
            'is_available' => true,
        ]);
        $draft = SurveyVersion::query()->create([
            'organization_id' => $organization->getKey(),
            'survey_definition_id' => $definition->getKey(),
            'version' => 1,
            'status' => SurveyVersionStatus::Draft,
            'title' => 'Мой тест',
            'title_en' => 'My test',
            'description' => 'Моё описание.',
            'description_en' => 'My description.',
            'definition' => ['sections' => []],
            'scoring' => ['metrics' => []],
            'source' => 'chuklov_approved',
            'approval_status' => 'draft',
            'methodology' => 'admin_custom',
        ]);

        app(InstallPlatformSurveyCatalog::class)->handle($organization);

        self::assertSame(1, $definition->fresh()->versions()->count());
        self::assertNull($definition->fresh()->active_version_id);
        self::assertSame('Мой тест', $draft->fresh()->title);
        self::assertSame('Моё описание.', $draft->fresh()->description);
    }

    public function test_completion_materializes_friendly_report_top_three_road_map_and_repeat_dynamics(): void
    {
        [$organization, $client] = $this->fixture();
        app(InstallPlatformSurveyCatalog::class)->handle($organization);
        $definition = SurveyDefinition::query()->where('organization_id', $organization->getKey())->where('definition_key', 'platform_health_9_systems')->firstOrFail();
        $this->approvePlatformDraftForTest($organization, $definition);

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
        $this->approvePlatformDraftForTest($organization, $definition);
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

    private function approvePlatformDraftForTest(Organization $organization, SurveyDefinition $definition): SurveyDefinition
    {
        app(OrganizationContext::class)->set($organization);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $draft = $definition->versions()->latest('version')->firstOrFail();
        $draft->forceFill(['approval_status' => 'approved'])->save();
        app(PublishSurveyVersion::class)->handle($admin, $draft);
        $definition->forceFill(['is_available' => true])->save();

        return $definition->refresh();
    }
}
