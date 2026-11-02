<?php

namespace Tests\Feature\Analytics;

use App\Filament\Pages\AnalyticsTagCandidates;
use App\Models\User;
use App\Modules\Analytics\Application\AcceptAnalyticsClassification;
use App\Modules\Analytics\Application\ClientCohortQuery;
use App\Modules\Analytics\Application\Data\ClientCohortFilters;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\MarkAnalyticsClassificationsStale;
use App\Modules\Analytics\Application\ReviewAnalyticsTagCandidate;
use App\Modules\Analytics\Application\SyncAnalyticsClassificationCandidates;
use App\Modules\Analytics\Domain\Enums\AnalyticsTagCandidateStatus;
use App\Modules\Analytics\Domain\Enums\ClassificationRunStatus;
use App\Modules\Analytics\Domain\Models\AnalyticsClassificationRun;
use App\Modules\Analytics\Domain\Models\AnalyticsTagCandidate;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\DTOs\UpdateMedicalProfileCommand;
use App\Modules\MedicalProfiles\Application\UpdateMedicalProfile;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfilePain;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

final class AnalyticsTagCandidateLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_suggestion_is_stored_for_review_and_can_be_renamed_or_rejected_without_creating_a_second_tag(): void
    {
        [$organization, $admin] = $this->fixture();
        $tag = MedicalProblemTag::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Существующая категория',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $run = $this->candidateRun($organization, [['id' => $tag->getKey(), 'label' => 'Существующая категория']]);

        app(SyncAnalyticsClassificationCandidates::class)->handle($run);
        $candidate = AnalyticsTagCandidate::query()->firstOrFail();

        app(ReviewAnalyticsTagCandidate::class)->handle($admin, (int) $candidate->getKey(), 'rename', 'Переименованный кандидат');
        self::assertDatabaseHas('analytics_tag_candidates', [
            'id' => $candidate->getKey(),
            'status' => AnalyticsTagCandidateStatus::Renamed->value,
            'reviewed_label' => 'Переименованный кандидат',
        ]);
        self::assertDatabaseCount('medical_problem_tags', 1);
        self::assertSame([['id' => $tag->getKey(), 'label' => 'Переименованный кандидат']], $run->refresh()->suggestions);

        $rejected = new AnalyticsTagCandidate;
        $rejected->forceFill([
            'organization_id' => $organization->getKey(),
            'classification_run_id' => $run->getKey(),
            'taxonomy' => 'complaints',
            'source_type' => 'synthetic',
            'source_id' => 2,
            'candidate_type' => 'existing',
            'suggested_existing_id' => 44,
            'label' => 'Отклоняемый кандидат',
            'status' => AnalyticsTagCandidateStatus::NeedsReview,
            'version' => 1,
        ]);
        $rejected->save();
        app(ReviewAnalyticsTagCandidate::class)->handle($admin, (int) $rejected->getKey(), 'reject');
        self::assertDatabaseHas('analytics_tag_candidates', [
            'id' => $rejected->getKey(),
            'status' => AnalyticsTagCandidateStatus::Rejected->value,
        ]);
    }

    public function test_new_candidate_is_not_accepted_without_owner_action_and_accept_creates_only_the_reviewed_taxonomy_tag(): void
    {
        [$organization, $admin] = $this->fixture();
        $run = $this->candidateRun($organization, [['id' => null, 'label' => 'Новая категория боли']]);
        $otherRun = $this->candidateRun($organization, [['id' => 99, 'label' => 'Другая категория боли']]);
        $candidate = new AnalyticsTagCandidate;
        $candidate->forceFill([
            'organization_id' => $organization->getKey(),
            'classification_run_id' => $run->getKey(),
            'client_id' => null,
            'taxonomy' => 'complaints',
            'source_type' => 'synthetic',
            'source_id' => 1,
            'candidate_type' => 'new',
            'label' => 'Новая категория боли',
            'status' => AnalyticsTagCandidateStatus::NeedsReview,
            'version' => 1,
        ]);
        $candidate->save();

        app(ReviewAnalyticsTagCandidate::class)->handle($admin, (int) $candidate->getKey(), 'accept');

        self::assertDatabaseHas('medical_problem_tags', [
            'organization_id' => $organization->getKey(),
            'name' => 'Новая категория боли',
            'is_active' => true,
        ]);
        self::assertDatabaseHas('analytics_tag_candidates', [
            'id' => $candidate->getKey(),
            'status' => AnalyticsTagCandidateStatus::Accepted->value,
        ]);
        self::assertSame(ClassificationRunStatus::NeedsReview, $run->refresh()->status);
        self::assertSame(ClassificationRunStatus::Stale, $otherRun->refresh()->status);
        self::assertSame([['id' => MedicalProblemTag::query()->where('organization_id', $organization->getKey())->where('name', 'Новая категория боли')->value('id'), 'label' => 'Новая категория боли']], $run->refresh()->suggestions);
    }

    public function test_new_candidate_can_be_renamed_before_it_is_created_and_then_accepted_with_the_reviewed_label(): void
    {
        [$organization, $admin] = $this->fixture();
        $run = $this->candidateRun($organization, [['id' => null, 'label' => 'Слишком длинное описание боли']]);
        $candidate = new AnalyticsTagCandidate;
        $candidate->forceFill([
            'organization_id' => $organization->getKey(),
            'classification_run_id' => $run->getKey(),
            'client_id' => null,
            'taxonomy' => 'complaints',
            'source_type' => 'synthetic',
            'source_id' => 1,
            'candidate_type' => 'new',
            'label' => 'Слишком длинное описание боли',
            'status' => AnalyticsTagCandidateStatus::NeedsReview,
            'version' => 1,
        ]);
        $candidate->save();

        app(ReviewAnalyticsTagCandidate::class)->handle($admin, (int) $candidate->getKey(), 'rename', 'Боль при наклоне');

        self::assertDatabaseCount('medical_problem_tags', 0);
        self::assertDatabaseHas('analytics_tag_candidates', [
            'id' => $candidate->getKey(),
            'status' => AnalyticsTagCandidateStatus::NeedsReview->value,
            'label' => 'Боль при наклоне',
            'reviewed_label' => 'Боль при наклоне',
        ]);
        self::assertSame([['id' => null, 'label' => 'Боль при наклоне']], $run->refresh()->suggestions);

        app(ReviewAnalyticsTagCandidate::class)->handle($admin, (int) $candidate->getKey(), 'accept');

        self::assertDatabaseHas('medical_problem_tags', [
            'organization_id' => $organization->getKey(),
            'name' => 'Боль при наклоне',
            'is_active' => true,
        ]);
        self::assertDatabaseHas('analytics_tag_candidates', [
            'id' => $candidate->getKey(),
            'status' => AnalyticsTagCandidateStatus::Accepted->value,
        ]);
    }

    public function test_new_profile_pain_candidate_is_reviewed_applied_and_visible_in_the_cohort(): void
    {
        [$organization, $admin] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create(['full_name' => 'Tagged source client']);
        $unrelated = Client::factory()->forOrganization($organization)->create(['full_name' => 'Unrelated client']);
        app(OrganizationContext::class)->set($organization);
        app(UpdateMedicalProfile::class)->handle($admin, $client, new UpdateMedicalProfileCommand(
            pains: [['name' => 'Боль при наклоне', 'score' => 7]],
            painsProvided: true,
        ));
        app(OrganizationContext::class)->set($organization);
        $pain = MedicalProfilePain::query()->where('organization_id', $organization->getKey())->sole();
        $run = $this->candidateRun($organization, [['id' => null, 'label' => 'Шея при наклоне']]);
        $run->forceFill([
            'client_id' => $client->getKey(),
            'source_type' => 'medical_profile_pain',
            'source_id' => $pain->getKey(),
        ])->save();
        $candidate = new AnalyticsTagCandidate;
        $candidate->forceFill([
            'organization_id' => $organization->getKey(),
            'classification_run_id' => $run->getKey(),
            'client_id' => $client->getKey(),
            'taxonomy' => 'complaints',
            'source_type' => 'medical_profile_pain',
            'source_id' => $pain->getKey(),
            'candidate_type' => 'new',
            'label' => 'Шея при наклоне',
            'status' => AnalyticsTagCandidateStatus::NeedsReview,
            'version' => 1,
        ])->save();

        app(ReviewAnalyticsTagCandidate::class)->handle($admin, (int) $candidate->getKey(), 'rename', 'Шея при наклоне финальная');
        self::assertSame([['id' => null, 'label' => 'Шея при наклоне финальная']], $run->refresh()->suggestions);
        app(ReviewAnalyticsTagCandidate::class)->handle($admin, (int) $candidate->getKey(), 'accept');
        $tag = MedicalProblemTag::query()
            ->where('organization_id', $organization->getKey())
            ->where('name', 'Шея при наклоне финальная')
            ->sole();

        $accepted = app(AcceptAnalyticsClassification::class)->handle(
            actor: $admin,
            runId: (int) $run->getKey(),
        );

        self::assertSame(ClassificationRunStatus::Succeeded, $accepted->status);
        self::assertSame([(int) $tag->getKey()], $pain->refresh()->problemTags()->pluck('medical_problem_tags.id')->all());
        app(OrganizationContext::class)->set($organization);
        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'],
            'UTC',
            CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
        );
        $cohortIds = app(ClientCohortQuery::class)->for(
            $admin,
            new ClientCohortFilters(problemTagIds: [(int) $tag->getKey()]),
            $period,
        )->pluck('clients.id')->all();

        self::assertSame([(int) $client->getKey()], $cohortIds);
        self::assertNotContains((int) $unrelated->getKey(), $cohortIds);
        self::assertSame(ClassificationRunStatus::Succeeded, $run->refresh()->status);
    }

    public function test_owner_can_explicitly_rename_an_existing_authoritative_tag_from_a_review_candidate(): void
    {
        [$organization, $admin] = $this->fixture();
        $tag = MedicalProblemTag::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Старая категория',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $run = $this->candidateRun($organization, [['id' => $tag->getKey(), 'label' => 'Старая категория']]);
        app(SyncAnalyticsClassificationCandidates::class)->handle($run);
        $candidate = AnalyticsTagCandidate::query()->firstOrFail();

        app(ReviewAnalyticsTagCandidate::class)->handle($admin, (int) $candidate->getKey(), 'rename_existing', 'Новая категория');

        self::assertDatabaseHas('medical_problem_tags', [
            'id' => $tag->getKey(),
            'name' => 'Новая категория',
        ]);
        self::assertDatabaseHas('analytics_tag_candidates', [
            'id' => $candidate->getKey(),
            'status' => AnalyticsTagCandidateStatus::Renamed->value,
            'reviewed_label' => 'Новая категория',
        ]);
    }

    public function test_pending_candidates_become_stale_when_their_classification_source_changes(): void
    {
        [$organization] = $this->fixture();
        $run = $this->candidateRun($organization, [['id' => null, 'label' => 'Устаревшая категория']]);
        $candidate = new AnalyticsTagCandidate;
        $candidate->forceFill([
            'organization_id' => $organization->getKey(),
            'classification_run_id' => $run->getKey(),
            'taxonomy' => 'complaints',
            'source_type' => $run->source_type,
            'source_id' => $run->source_id,
            'candidate_type' => 'new',
            'label' => 'Устаревшая категория',
            'status' => AnalyticsTagCandidateStatus::NeedsReview,
            'version' => 1,
        ])->save();

        app(MarkAnalyticsClassificationsStale::class)->forSource((int) $organization->getKey(), (string) $run->source_type, (int) $run->source_id);

        self::assertDatabaseHas('analytics_tag_candidates', [
            'id' => $candidate->getKey(),
            'status' => AnalyticsTagCandidateStatus::Stale->value,
        ]);
    }

    public function test_candidate_review_cannot_cross_organization_boundary(): void
    {
        [$organization, $admin] = $this->fixture();
        [$otherOrganization] = $this->fixture();
        $run = $this->candidateRun($otherOrganization, []);
        $candidate = new AnalyticsTagCandidate;
        $candidate->forceFill([
            'organization_id' => $otherOrganization->getKey(),
            'classification_run_id' => $run->getKey(),
            'taxonomy' => 'complaints',
            'source_type' => 'synthetic',
            'source_id' => 1,
            'candidate_type' => 'new',
            'label' => 'Чужая категория',
            'status' => AnalyticsTagCandidateStatus::NeedsReview,
            'version' => 1,
        ]);
        $candidate->save();
        app(OrganizationContext::class)->set($organization);

        $this->expectException(ValidationException::class);
        app(ReviewAnalyticsTagCandidate::class)->handle($admin, (int) $candidate->getKey(), 'accept');
    }

    public function test_pending_candidate_review_controls_are_enabled_in_the_filament_page(): void
    {
        [$organization, $admin] = $this->fixture();
        $run = $this->candidateRun($organization, [['id' => null, 'label' => 'Browser candidate']]);
        $candidate = new AnalyticsTagCandidate;
        $candidate->forceFill([
            'organization_id' => $organization->getKey(),
            'classification_run_id' => $run->getKey(),
            'taxonomy' => 'complaints',
            'source_type' => 'synthetic',
            'source_id' => 3,
            'candidate_type' => 'new',
            'label' => 'Browser candidate',
            'status' => AnalyticsTagCandidateStatus::NeedsReview,
            'version' => 1,
        ])->save();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        /** @var Testable<AnalyticsTagCandidates> $component */
        $component = Livewire::actingAs($admin)
            ->test(new AnalyticsTagCandidates)
            ->assertSuccessful();
        $html = $component->html();

        self::assertDoesNotMatchRegularExpression(
            '/<select[^>]+wire:model\.live="data\.candidates\.[^"]+\.decision"[^>]+disabled="disabled"/s',
            $html,
        );
    }

    public function test_stale_candidate_review_shows_an_actionable_filament_notification(): void
    {
        [$organization, $admin] = $this->fixture();
        $run = $this->candidateRun($organization, [['id' => null, 'label' => 'Stale browser candidate']]);
        $candidate = new AnalyticsTagCandidate;
        $candidate->forceFill([
            'organization_id' => $organization->getKey(),
            'classification_run_id' => $run->getKey(),
            'taxonomy' => 'complaints',
            'source_type' => (string) $run->source_type,
            'source_id' => (int) $run->source_id,
            'candidate_type' => 'new',
            'label' => 'Stale browser candidate',
            'status' => AnalyticsTagCandidateStatus::NeedsReview,
            'version' => 1,
        ])->save();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        /** @var Testable<AnalyticsTagCandidates> $component */
        $component = Livewire::actingAs($admin)->test(new AnalyticsTagCandidates);
        $state = $component->instance()->data;
        self::assertIsArray($state);
        self::assertIsArray($state['candidates'] ?? null);
        $key = array_key_first($state['candidates']);
        self::assertIsString($key);
        $state['candidates'][$key]['decision'] = 'accept';

        app(MarkAnalyticsClassificationsStale::class)->forSource(
            (int) $organization->getKey(),
            (string) $run->source_type,
            (int) $run->source_id,
        );

        $component->fillForm($state)
            ->call('save')
            ->assertNotified('Кандидат уже обработан или устарел.');

        self::assertDatabaseHas('analytics_tag_candidates', [
            'id' => $candidate->getKey(),
            'status' => AnalyticsTagCandidateStatus::Stale->value,
        ]);
    }

    /** @param list<array<string, mixed>> $suggestions */
    private function candidateRun(Organization $organization, array $suggestions): AnalyticsClassificationRun
    {
        app(OrganizationContext::class)->set($organization);

        $run = new AnalyticsClassificationRun;
        $run->forceFill([
            'organization_id' => $organization->getKey(),
            'taxonomy' => 'complaints',
            'source_type' => 'synthetic',
            'source_id' => random_int(1, 999999),
            'source_digest' => hash('sha256', json_encode($suggestions, JSON_THROW_ON_ERROR)),
            'status' => ClassificationRunStatus::NeedsReview,
            'suggestions' => $suggestions,
        ]);
        $run->save();

        return $run;
    }

    /** @return array{0: Organization, 1: User} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $organization->featureFlags()->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();

        return [$organization, $admin];
    }
}
