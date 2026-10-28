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
        self::assertSame(1, $calls);
        self::assertSame(1, AnalyticsClassificationRun::query()->where('organization_id', $organization->getKey())->count());

        $acceptedAgain = app(AcceptAnalyticsClassification::class)->handle(
            actor: $admin,
            runId: $run->runId,
            themeIds: [$theme->getKey()],
        );

        self::assertSame(ClassificationRunStatus::Succeeded, $acceptedAgain->status);
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
