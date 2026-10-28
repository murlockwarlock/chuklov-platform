<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Application\Data\AiRunResult;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Analytics\Application\AcceptAnalyticsClassification;
use App\Modules\Analytics\Application\SuggestAnalyticsTags;
use App\Modules\Analytics\Domain\Enums\ClassificationRunStatus;
use App\Modules\Analytics\Domain\Models\AnalyticsClassificationRun;
use App\Modules\Feedback\Domain\Models\FeedbackSubmission;
use App\Modules\Feedback\Domain\Models\FeedbackTheme;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\DTOs\UpdateMedicalProfileCommand;
use App\Modules\MedicalProfiles\Application\UpdateMedicalProfile;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfilePain;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Tracker\Domain\Enums\TrackerTaskFrequency;
use App\Modules\Tracker\Domain\Enums\TrackerTaskType;
use App\Modules\Tracker\Domain\Models\TrackerGoalTag;
use App\Modules\Tracker\Domain\Models\TrackerTask;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AnalyticsAiClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_suggestions_are_schema_validated_persisted_and_idempotent(): void
    {
        [$organization, $admin] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        $submission = new FeedbackSubmission;
        $submission->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'score' => 4,
            'internal_feedback' => 'После курса хотелось больше времени на восстановление.',
            'source' => 'portal',
            'idempotency_key' => 'classification-feedback',
            'request_hash' => hash('sha256', 'classification-feedback'),
            'submitted_at' => now(),
        ])->save();
        $theme = new FeedbackTheme;
        $theme->forceFill([
            'organization_id' => $organization->getKey(),
            'name' => 'Время восстановления',
            'group_name' => 'Процесс',
            'is_active' => true,
            'sort_order' => 1,
        ])->save();
        $this->activePrompt($organization);
        $calls = 0;
        $this->app->instance(AiWorkflowEngine::class, new class($calls) implements AiWorkflowEngine
        {
            public function __construct(private int &$calls) {}

            public function run(int $organizationId, AiRunRequest $request): AiRunResult
            {
                $this->calls++;
                $aiRun = new AiRun;
                $aiRun->forceFill([
                    'organization_id' => $organizationId,
                    'capability' => $request->capability,
                    'workflow_key' => $request->workflowKey,
                    'origin' => $request->origin,
                    'initiated_by_user_id' => $request->initiatedByUserId,
                    'client_id' => $request->clientId,
                    'status' => AiRunStatus::Succeeded,
                    'execution_mode' => $request->executionMode,
                    'prompt_version_id' => $request->promptVersionId,
                    'idempotency_key' => $request->idempotencyKey,
                ])->save();

                return new AiRunResult(
                    runId: (int) $aiRun->getKey(),
                    status: AiRunStatus::Succeeded,
                    outputPayload: ['tags' => ['Процесс: Время восстановления']],
                );
            }

            public function executeRun(int $organizationId, int $runId, string $workerLeaseToken): AiRunResult
            {
                throw new \LogicException('Not used in classification test.');
            }
        });
        app(OrganizationContext::class)->set($organization);

        $run = app(SuggestAnalyticsTags::class)->handle(
            actor: $admin,
            taxonomy: 'feedback',
            sourceType: 'feedback_submission',
            sourceId: (int) $submission->getKey(),
            clientId: (int) $client->getKey(),
            sourceText: (string) $submission->internal_feedback,
            availableTags: [['id' => (int) $theme->getKey(), 'label' => 'Процесс: Время восстановления']],
        );

        self::assertSame(ClassificationRunStatus::NeedsReview, $run->status);
        self::assertSame([['id' => $theme->getKey(), 'label' => 'Процесс: Время восстановления']], $run->suggestions);
        self::assertSame(1, $calls);

        $accepted = app(AcceptAnalyticsClassification::class)->handle(
            actor: $admin,
            runId: $run->runId,
            themeIds: [$theme->getKey()],
        );

        self::assertSame(ClassificationRunStatus::Succeeded, $accepted->status);
        self::assertSame([$theme->getKey()], $submission->refresh()->themes()->pluck('feedback_themes.id')->all());

        $reprocessed = app(SuggestAnalyticsTags::class)->handle(
            actor: $admin,
            taxonomy: 'feedback',
            sourceType: 'feedback_submission',
            sourceId: (int) $submission->getKey(),
            clientId: (int) $client->getKey(),
            sourceText: (string) $submission->internal_feedback,
            availableTags: [['id' => (int) $theme->getKey(), 'label' => 'Процесс: Время восстановления']],
            force: true,
        );

        self::assertSame($run->runId, $reprocessed->runId);
        self::assertSame(ClassificationRunStatus::NeedsReview, $reprocessed->status);
        self::assertSame(2, $calls);

        $replayed = app(SuggestAnalyticsTags::class)->handle(
            actor: $admin,
            taxonomy: 'feedback',
            sourceType: 'feedback_submission',
            sourceId: (int) $submission->getKey(),
            clientId: (int) $client->getKey(),
            sourceText: (string) $submission->internal_feedback,
            availableTags: [['id' => (int) $theme->getKey(), 'label' => 'Процесс: Время восстановления']],
        );

        self::assertSame($run->runId, $replayed->runId);
        self::assertSame(2, $calls);
        self::assertSame(1, AnalyticsClassificationRun::query()->where('organization_id', $organization->getKey())->count());

        $acceptedAgain = app(AcceptAnalyticsClassification::class)->handle(
            actor: $admin,
            runId: $run->runId,
            themeIds: [$theme->getKey()],
        );

        self::assertSame(ClassificationRunStatus::Succeeded, $acceptedAgain->status);

        $changed = app(SuggestAnalyticsTags::class)->handle(
            actor: $admin,
            taxonomy: 'feedback',
            sourceType: 'feedback_submission',
            sourceId: (int) $submission->getKey(),
            clientId: (int) $client->getKey(),
            sourceText: 'После курса хотелось больше времени на восстановление и сна.',
            availableTags: [['id' => (int) $theme->getKey(), 'label' => 'Процесс: Время восстановления']],
        );

        self::assertNotSame($run->runId, $changed->runId);
        self::assertSame(ClassificationRunStatus::NeedsReview, $changed->status);
        self::assertSame(ClassificationRunStatus::Stale, AnalyticsClassificationRun::query()->findOrFail($run->runId)->status);
        self::assertSame(3, $calls);
    }

    public function test_invalid_ai_output_fails_without_assigning_tags_and_can_retry(): void
    {
        [$organization, $admin] = $this->fixture();
        $this->activePrompt($organization);
        $calls = 0;
        $this->app->instance(AiWorkflowEngine::class, new class($calls) implements AiWorkflowEngine
        {
            public function __construct(private int &$calls) {}

            public function run(int $organizationId, AiRunRequest $request): AiRunResult
            {
                $this->calls++;

                return new AiRunResult(
                    runId: 702,
                    status: AiRunStatus::Succeeded,
                    outputPayload: ['tags' => ['Неизвестная категория']],
                );
            }

            public function executeRun(int $organizationId, int $runId, string $workerLeaseToken): AiRunResult
            {
                throw new \LogicException('Not used in classification test.');
            }
        });
        app(OrganizationContext::class)->set($organization);

        $result = app(SuggestAnalyticsTags::class)->handle(
            actor: $admin,
            taxonomy: 'goals',
            sourceType: 'tracker_task',
            sourceId: 99,
            clientId: null,
            sourceText: 'Хочу легче двигаться.',
            availableTags: [['id' => 1, 'label' => 'Подвижность']],
        );

        self::assertSame(ClassificationRunStatus::Failed, $result->status);
        self::assertSame('invalid_output', $result->errorCode);
        self::assertSame([], $result->suggestions);
        self::assertSame(1, $calls);
    }

    public function test_goal_suggestions_are_reviewed_and_applied_to_the_tenant_task(): void
    {
        [$organization, $admin] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        $task = new TrackerTask;
        $task->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'title' => 'Ежедневная практика движения',
            'task_type' => TrackerTaskType::Practice->value,
            'frequency' => TrackerTaskFrequency::Daily->value,
            'starts_on' => '2026-10-01',
            'ends_on' => null,
            'active' => true,
            'display_order' => 1,
        ])->save();
        $tag = new TrackerGoalTag;
        $tag->forceFill([
            'organization_id' => $organization->getKey(),
            'name' => 'Подвижность',
            'group_name' => 'Движение',
            'is_active' => true,
            'sort_order' => 1,
        ])->save();
        $this->activePrompt($organization);
        $this->app->instance(AiWorkflowEngine::class, new class implements AiWorkflowEngine
        {
            public function run(int $organizationId, AiRunRequest $request): AiRunResult
            {
                $aiRun = new AiRun;
                $aiRun->forceFill([
                    'organization_id' => $organizationId,
                    'capability' => $request->capability,
                    'workflow_key' => $request->workflowKey,
                    'origin' => $request->origin,
                    'initiated_by_user_id' => $request->initiatedByUserId,
                    'client_id' => $request->clientId,
                    'status' => AiRunStatus::Succeeded,
                    'execution_mode' => $request->executionMode,
                    'prompt_version_id' => $request->promptVersionId,
                    'idempotency_key' => $request->idempotencyKey,
                ])->save();

                return new AiRunResult(
                    runId: (int) $aiRun->getKey(),
                    status: AiRunStatus::Succeeded,
                    outputPayload: ['tags' => ['Движение: Подвижность']],
                );
            }

            public function executeRun(int $organizationId, int $runId, string $workerLeaseToken): AiRunResult
            {
                throw new \LogicException('Not used in classification test.');
            }
        });
        app(OrganizationContext::class)->set($organization);

        $run = app(SuggestAnalyticsTags::class)->handle(
            actor: $admin,
            taxonomy: 'goals',
            sourceType: 'tracker_task',
            sourceId: (int) $task->getKey(),
            clientId: (int) $client->getKey(),
            sourceText: $task->title,
            availableTags: [['id' => (int) $tag->getKey(), 'label' => 'Движение: Подвижность']],
        );

        $accepted = app(AcceptAnalyticsClassification::class)->handle(
            actor: $admin,
            runId: $run->runId,
        );

        self::assertSame(ClassificationRunStatus::Succeeded, $accepted->status);
        self::assertSame([(int) $tag->getKey()], $task->refresh()->goalTags()->pluck('tracker_goal_tags.id')->all());
    }

    public function test_complaint_classification_can_be_manually_corrected_on_a_profile_pain(): void
    {
        [$organization, $admin] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        app(UpdateMedicalProfile::class)->handle($admin, $client, new UpdateMedicalProfileCommand(
            pains: [['name' => 'Шея', 'score' => 7]],
            painsProvided: true,
        ));
        $pain = MedicalProfilePain::query()->where('organization_id', $organization->getKey())->sole();
        $tag = new MedicalProblemTag;
        $tag->forceFill([
            'organization_id' => $organization->getKey(),
            'name' => 'Шейный отдел',
            'group_name' => 'Синтетика',
            'is_active' => true,
            'sort_order' => 1,
        ])->save();
        $run = new AnalyticsClassificationRun;
        $run->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'taxonomy' => 'complaints',
            'source_type' => 'medical_profile_pain',
            'source_id' => $pain->getKey(),
            'source_digest' => hash('sha256', 'profile-pain-classification'),
            'status' => ClassificationRunStatus::NeedsReview,
            'attempt_count' => 1,
            'suggestions' => [['id' => $tag->getKey(), 'label' => 'Синтетика: Шейный отдел']],
        ])->save();
        app(OrganizationContext::class)->set($organization);

        $accepted = app(AcceptAnalyticsClassification::class)->handle(
            actor: $admin,
            runId: (int) $run->getKey(),
            themeIds: [$tag->getKey()],
        );

        self::assertSame(ClassificationRunStatus::Succeeded, $accepted->status);
        self::assertSame([(int) $tag->getKey()], $pain->refresh()->problemTags()->pluck('medical_problem_tags.id')->all());
    }

    public function test_source_and_taxonomy_changes_invalidate_reviewed_suggestions_without_touching_processing_runs(): void
    {
        [$organization] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        $submission = new FeedbackSubmission;
        $submission->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'score' => 3,
            'internal_feedback' => 'Нужно больше времени на восстановление.',
            'source' => 'crm',
            'idempotency_key' => 'stale-source',
            'request_hash' => hash('sha256', 'stale-source'),
            'submitted_at' => now(),
        ])->save();
        $theme = new FeedbackTheme;
        $theme->forceFill([
            'organization_id' => $organization->getKey(),
            'name' => 'Восстановление',
            'group_name' => 'Процесс',
            'is_active' => true,
            'sort_order' => 1,
        ])->save();
        $run = new AnalyticsClassificationRun;
        $run->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'taxonomy' => 'feedback',
            'source_type' => 'feedback_submission',
            'source_id' => $submission->getKey(),
            'source_digest' => hash('sha256', 'feedback-source'),
            'status' => ClassificationRunStatus::NeedsReview,
            'attempt_count' => 1,
            'suggestions' => [['id' => $theme->getKey(), 'label' => 'Процесс: Восстановление']],
        ])->save();

        $submission->forceFill(['internal_feedback' => 'Нужно больше времени и сна для восстановления.'])->save();

        self::assertSame(ClassificationRunStatus::Stale, $run->refresh()->status);
        self::assertNull($run->suggestions);
        self::assertSame('source_changed', $run->error_code);

        $processing = new AnalyticsClassificationRun;
        $processing->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'taxonomy' => 'feedback',
            'source_type' => 'feedback_submission',
            'source_id' => $submission->getKey(),
            'source_digest' => hash('sha256', 'feedback-processing'),
            'status' => ClassificationRunStatus::Processing,
            'attempt_count' => 1,
        ])->save();
        $submission->forceFill(['internal_feedback' => 'Нужно больше сна и отдыха.'])->save();

        self::assertSame(ClassificationRunStatus::Processing, $processing->refresh()->status);

        $taxonomyRun = new AnalyticsClassificationRun;
        $taxonomyRun->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'taxonomy' => 'feedback',
            'source_type' => 'feedback_submission',
            'source_id' => $submission->getKey(),
            'source_digest' => hash('sha256', 'feedback-taxonomy'),
            'status' => ClassificationRunStatus::NeedsReview,
            'attempt_count' => 1,
        ])->save();
        $theme->forceFill(['group_name' => 'Сервис'])->save();

        self::assertSame(ClassificationRunStatus::Processing, $processing->refresh()->status);
        self::assertSame(ClassificationRunStatus::Stale, $taxonomyRun->refresh()->status);
    }

    public function test_medical_pain_and_goal_source_changes_invalidate_their_classifications(): void
    {
        [$organization, $admin] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        app(UpdateMedicalProfile::class)->handle($admin, $client, new UpdateMedicalProfileCommand(
            pains: [['name' => 'Плечо', 'score' => 6]],
            painsProvided: true,
        ));
        $pain = MedicalProfilePain::query()->where('organization_id', $organization->getKey())->sole();
        $painRun = new AnalyticsClassificationRun;
        $painRun->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'taxonomy' => 'complaints',
            'source_type' => 'medical_profile_pain',
            'source_id' => $pain->getKey(),
            'source_digest' => hash('sha256', 'pain-source'),
            'status' => ClassificationRunStatus::NeedsReview,
            'attempt_count' => 1,
        ])->save();
        $pain->forceFill(['name' => $pain->getRawOriginal('name').'changed'])->save();

        self::assertSame(ClassificationRunStatus::Stale, $painRun->refresh()->status);

        $task = new TrackerTask;
        $task->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'title' => 'Практика движения',
            'task_type' => TrackerTaskType::Practice->value,
            'frequency' => TrackerTaskFrequency::Daily->value,
            'starts_on' => '2026-10-01',
            'active' => true,
            'display_order' => 1,
        ])->save();
        $taskRun = new AnalyticsClassificationRun;
        $taskRun->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'taxonomy' => 'goals',
            'source_type' => 'tracker_task',
            'source_id' => $task->getKey(),
            'source_digest' => hash('sha256', 'task-source'),
            'status' => ClassificationRunStatus::NeedsReview,
            'attempt_count' => 1,
        ])->save();
        $task->forceFill(['title' => 'Практика дыхания'])->save();

        self::assertSame(ClassificationRunStatus::Stale, $taskRun->refresh()->status);
    }

    /** @return array{0: Organization, 1: User} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }

    private function activePrompt(Organization $organization): void
    {
        $prompt = AiPrompt::query()->create([
            'organization_id' => $organization->getKey(),
            'key' => 'analytics_classification',
            'name' => 'Классификация аналитики',
            'capability' => AiCapability::GeneralAssistant,
        ]);
        $version = AiPromptVersion::query()->create([
            'organization_id' => $organization->getKey(),
            'prompt_id' => $prompt->getKey(),
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'Return JSON tags from the configured taxonomy.',
            'user_prompt_template' => '{{source_text}} {{allowed_tags}}',
            'output_schema' => ['type' => 'object', 'properties' => ['tags' => ['type' => 'array']]],
            'activated_at' => CarbonImmutable::now(),
        ]);
        $prompt->forceFill(['active_version_id' => $version->getKey()])->save();
    }
}
