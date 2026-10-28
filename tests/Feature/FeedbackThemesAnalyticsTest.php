<?php

namespace Tests\Feature;

use App\Filament\Resources\FeedbackSubmissions\Pages\CreateFeedbackSubmission;
use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\FeedbackAnalytics;
use App\Modules\Feedback\Application\ClassifyFeedbackSubmission;
use App\Modules\Feedback\Application\RecordNpsSubmission;
use App\Modules\Feedback\Application\SaveFeedbackConfiguration;
use App\Modules\Feedback\Domain\Models\FeedbackSubmission;
use App\Modules\Feedback\Domain\Models\FeedbackTheme;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class FeedbackThemesAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_feedback_themes_support_zero_score_manual_classification_and_aggregate_analytics(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        app(SaveFeedbackConfiguration::class)->handle(
            actor: $admin,
            enabled: true,
            positiveThreshold: 8,
            lowScoreFeedbackRequired: false,
            reviewUrlRu: null,
            reviewUrlEn: null,
            feedbackThemes: [[
                'name' => 'Больше времени на вопросы',
                'group_name' => 'Сервис',
                'is_active' => true,
                'sort_order' => 1,
            ]],
        );
        $theme = FeedbackTheme::query()->where('organization_id', $organization->getKey())->sole();

        $submission = app(RecordNpsSubmission::class)->handle(
            client: $client,
            score: 0,
            internalFeedback: 'Синтетический комментарий',
            idempotencyKey: 'feedback-zero-score',
            themeIds: [$theme->getKey()],
        );
        self::assertSame(0, $submission->score);
        self::assertSame([$theme->getKey()], $submission->themes()->pluck('feedback_themes.id')->all());

        app(ClassifyFeedbackSubmission::class)->handle($admin, $submission, []);
        self::assertCount(0, FeedbackSubmission::query()->findOrFail($submission->getKey())->themes);

        app(ClassifyFeedbackSubmission::class)->handle($admin, $submission, [$theme->getKey()]);
        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'],
            'UTC',
            CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
        );
        $data = app(FeedbackAnalytics::class)->handle($admin, $period);

        self::assertSame(1, $data->submissionCount);
        self::assertSame(0.0, $data->averageScore);
        self::assertSame('Больше времени на вопросы', $data->themes[0]['name']);
        self::assertSame(1, $data->themes[0]['client_count']);
        $clientData = app(FeedbackAnalytics::class)->handle($admin, $period, $client);
        self::assertSame(1, $clientData->submissionCount);
        self::assertSame(0.0, $clientData->averageScore);
    }

    public function test_specialist_can_record_feedback_for_a_client_through_the_crm_form(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        app(SaveFeedbackConfiguration::class)->handle(
            actor: $admin,
            enabled: true,
            positiveThreshold: 8,
            lowScoreFeedbackRequired: false,
            reviewUrlRu: null,
            reviewUrlEn: null,
            feedbackThemes: [[
                'name' => 'Больше времени на вопросы',
                'group_name' => 'Сервис',
                'is_active' => true,
                'sort_order' => 1,
            ]],
        );
        $theme = FeedbackTheme::query()->where('organization_id', $organization->getKey())->sole();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);
        Livewire::test(CreateFeedbackSubmission::class)
            ->fillForm([
                'client_id' => $client->getKey(),
                'score' => 4,
                'internal_feedback' => 'Нужно больше времени на разбор ощущений.',
                'theme_ids' => [$theme->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $submission = FeedbackSubmission::query()->where('organization_id', $organization->getKey())->sole();
        self::assertSame('crm', $submission->source);
        self::assertSame(4, $submission->score);
        self::assertSame([$theme->getKey()], $submission->themes()->pluck('feedback_themes.id')->all());
        self::assertSame('Нужно больше времени на разбор ощущений.', $submission->internal_feedback);
    }

    /** @return array{0: Organization, 1: User, 2: Client} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin, $client];
    }
}
