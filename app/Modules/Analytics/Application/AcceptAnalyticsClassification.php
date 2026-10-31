<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Domain\Enums\ClassificationRunStatus;
use App\Modules\Analytics\Domain\Models\AnalyticsClassificationRun;
use App\Modules\Feedback\Application\FeedbackThemeCatalog;
use App\Modules\Feedback\Domain\Models\FeedbackSubmission;
use App\Modules\MedicalProfiles\Application\MedicalProblemTagCatalog;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfilePain;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Sessions\Domain\Models\MedicalSessionPain;
use App\Modules\Tracker\Application\TrackerGoalTagCatalog;
use App\Modules\Tracker\Domain\Models\TrackerTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AcceptAnalyticsClassification
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private FeedbackThemeCatalog $themes,
        private MedicalProblemTagCatalog $problemTags,
        private TrackerGoalTagCatalog $goalTags,
        private RecordAuditEvent $audit,
    ) {}

    /** @param array<int, mixed>|null $themeIds */
    public function handle(User $actor, int $runId, ?array $themeIds = null): AnalyticsClassificationRun
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);

        return DB::transaction(function () use ($actor, $organization, $runId, $themeIds): AnalyticsClassificationRun {
            $run = AnalyticsClassificationRun::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($runId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($run->status === ClassificationRunStatus::Succeeded) {
                return $run;
            }
            if ($run->status !== ClassificationRunStatus::NeedsReview) {
                throw ValidationException::withMessages([
                    'classification' => 'Предложения недоступны для принятия.',
                ]);
            }
            if ($run->taxonomy === 'feedback' && $run->source_type === 'feedback_submission') {
                $selectedIds = $this->acceptFeedback($run, (int) $organization->getKey(), $themeIds);
            } elseif ($run->taxonomy === 'goals' && $run->source_type === 'tracker_task') {
                $selectedIds = $this->acceptGoalTags($run, (int) $organization->getKey(), $themeIds);
            } elseif ($run->taxonomy === 'complaints' && $run->source_type === 'medical_profile_pain') {
                $selectedIds = $this->acceptProblemTags($run, (int) $organization->getKey(), $themeIds);
            } elseif ($run->taxonomy === 'complaints' && $run->source_type === 'medical_session_pain') {
                $selectedIds = $this->acceptSessionProblemTags($run, (int) $organization->getKey(), $themeIds);
            } else {
                throw ValidationException::withMessages([
                    'classification' => 'Этот тип классификации нельзя принять в CRM.',
                ]);
            }
            $run->forceFill([
                'status' => ClassificationRunStatus::Succeeded,
                'accepted_by_user_id' => $actor->getKey(),
                'accepted_at' => now(),
                'error_code' => null,
                'error_message_sanitized' => null,
                'updated_at' => now(),
            ])->save();
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'analytics.classification.accepted',
                targetType: AnalyticsClassificationRun::class,
                targetId: (string) $run->getKey(),
                metadata: [
                    'taxonomy' => $run->taxonomy,
                    'source_type' => $run->source_type,
                    'tag_count' => count($selectedIds),
                ],
            );

            return $run->refresh();
        });
    }

    /**
     * @param  array<int, mixed>|null  $themeIds
     * @return list<int>
     */
    private function acceptFeedback(AnalyticsClassificationRun $run, int $organizationId, ?array $themeIds): array
    {
        $submission = FeedbackSubmission::query()
            ->where('organization_id', $organizationId)
            ->whereKey($run->source_id)
            ->lockForUpdate()
            ->firstOrFail();
        if ($run->client_id === null || (int) $run->client_id !== (int) $submission->client_id) {
            throw ValidationException::withMessages([
                'classification' => 'Обратная связь не принадлежит указанному клиенту.',
            ]);
        }
        $suggestedIds = $this->suggestedIds($run->suggestions);
        $selectedIds = $themeIds === null ? $suggestedIds : $themeIds;
        $preservedIds = $submission->themes()->pluck('feedback_themes.id')->all();
        $selectedIds = $this->themes->assertKnown($organizationId, $selectedIds, $preservedIds);

        $submission->themes()->syncWithPivotValues($selectedIds, [
            'organization_id' => $organizationId,
            'client_id' => $submission->client_id,
        ]);

        return $selectedIds;
    }

    /**
     * @param  array<int, mixed>|null  $themeIds
     * @return list<int>
     */
    private function acceptGoalTags(AnalyticsClassificationRun $run, int $organizationId, ?array $themeIds): array
    {
        $task = TrackerTask::query()
            ->where('organization_id', $organizationId)
            ->whereKey($run->source_id)
            ->lockForUpdate()
            ->firstOrFail();
        if ($run->client_id !== null && (int) $run->client_id !== (int) $task->client_id) {
            throw ValidationException::withMessages([
                'classification' => 'Задача не принадлежит указанному клиенту.',
            ]);
        }
        $suggestedIds = $this->suggestedIds($run->suggestions);
        $selectedIds = $themeIds === null ? $suggestedIds : $themeIds;
        $preservedIds = $task->goalTags()->pluck('tracker_goal_tags.id')->all();
        $selectedIds = $this->goalTags->assertKnown($organizationId, $selectedIds, $preservedIds);

        $task->goalTags()->syncWithPivotValues($selectedIds, [
            'organization_id' => $organizationId,
            'client_id' => $task->client_id,
        ]);

        return $selectedIds;
    }

    /**
     * @param  array<int, mixed>|null  $themeIds
     * @return list<int>
     */
    private function acceptProblemTags(AnalyticsClassificationRun $run, int $organizationId, ?array $themeIds): array
    {
        $pain = MedicalProfilePain::query()
            ->where('organization_id', $organizationId)
            ->whereKey($run->source_id)
            ->lockForUpdate()
            ->firstOrFail();
        if ($run->client_id !== null && (int) $run->client_id !== (int) $pain->client_id) {
            throw ValidationException::withMessages([
                'classification' => 'Боль не принадлежит указанному клиенту.',
            ]);
        }
        $suggestedIds = $this->suggestedIds($run->suggestions);
        $selectedIds = $themeIds === null ? $suggestedIds : $themeIds;
        $preservedIds = $pain->problemTags()->pluck('medical_problem_tags.id')->all();
        $selectedIds = $this->problemTags->assertKnown($organizationId, $selectedIds, $preservedIds);

        $pain->problemTags()->syncWithPivotValues($selectedIds, [
            'organization_id' => $organizationId,
            'client_id' => $pain->client_id,
        ]);

        return $selectedIds;
    }

    /**
     * @param  array<int, mixed>|null  $themeIds
     * @return list<int>
     */
    private function acceptSessionProblemTags(AnalyticsClassificationRun $run, int $organizationId, ?array $themeIds): array
    {
        $pain = MedicalSessionPain::query()
            ->where('organization_id', $organizationId)
            ->whereKey($run->source_id)
            ->lockForUpdate()
            ->firstOrFail();
        if ($run->client_id !== null && (int) $run->client_id !== (int) $pain->client_id) {
            throw ValidationException::withMessages([
                'classification' => 'Боль сеанса не принадлежит указанному клиенту.',
            ]);
        }
        $suggestedIds = $this->suggestedIds($run->suggestions);
        $selectedIds = $themeIds === null ? $suggestedIds : $themeIds;
        $preservedIds = $pain->problemTags()->pluck('medical_problem_tags.id')->all();
        $selectedIds = $this->problemTags->assertKnown($organizationId, $selectedIds, $preservedIds);

        $pain->problemTags()->syncWithPivotValues($selectedIds, [
            'organization_id' => $organizationId,
            'client_id' => $pain->client_id,
        ]);

        return $selectedIds;
    }

    /** @return list<int> */
    private function suggestedIds(mixed $suggestions): array
    {
        if (! is_array($suggestions)) {
            return [];
        }

        return array_values(array_map(
            static fn (array $suggestion): int => (int) ($suggestion['id'] ?? 0),
            array_filter($suggestions, static fn (mixed $suggestion): bool => is_array($suggestion)),
        ));
    }
}
