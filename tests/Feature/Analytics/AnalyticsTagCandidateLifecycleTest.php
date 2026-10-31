<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Analytics\Application\MarkAnalyticsClassificationsStale;
use App\Modules\Analytics\Application\ReviewAnalyticsTagCandidate;
use App\Modules\Analytics\Application\SyncAnalyticsClassificationCandidates;
use App\Modules\Analytics\Domain\Enums\AnalyticsTagCandidateStatus;
use App\Modules\Analytics\Domain\Enums\ClassificationRunStatus;
use App\Modules\Analytics\Domain\Models\AnalyticsClassificationRun;
use App\Modules\Analytics\Domain\Models\AnalyticsTagCandidate;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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
        self::assertSame([['id' => MedicalProblemTag::query()->where('organization_id', $organization->getKey())->where('name', 'Новая категория боли')->value('id'), 'label' => 'Новая категория боли']], $run->refresh()->suggestions);
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
