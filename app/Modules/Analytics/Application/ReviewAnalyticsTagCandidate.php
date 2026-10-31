<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Domain\Enums\AnalyticsTagCandidateStatus;
use App\Modules\Analytics\Domain\Enums\ClassificationRunStatus;
use App\Modules\Analytics\Domain\Models\AnalyticsClassificationRun;
use App\Modules\Analytics\Domain\Models\AnalyticsTagCandidate;
use App\Modules\Feedback\Domain\Models\FeedbackTheme;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Tracker\Domain\Models\TrackerGoalTag;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ReviewAnalyticsTagCandidate
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, int $candidateId, string $decision, ?string $label = null, ?int $targetTagId = null): AnalyticsTagCandidate
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);
        $decision = trim($decision);
        if (! in_array($decision, ['accept', 'rename', 'merge', 'reject'], true)) {
            throw ValidationException::withMessages(['candidate' => 'Выберите корректное решение по кандидату.']);
        }

        return DB::transaction(function () use ($actor, $organization, $candidateId, $decision, $label, $targetTagId): AnalyticsTagCandidate {
            $candidate = AnalyticsTagCandidate::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($candidateId)
                ->lockForUpdate()
                ->first();
            if (! $candidate instanceof AnalyticsTagCandidate) {
                throw ValidationException::withMessages(['candidate' => 'Кандидат не принадлежит текущей организации.']);
            }
            if ($candidate->status !== AnalyticsTagCandidateStatus::NeedsReview) {
                throw ValidationException::withMessages(['candidate' => 'Кандидат уже обработан или устарел.']);
            }

            $run = AnalyticsClassificationRun::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($candidate->classification_run_id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($run->status !== ClassificationRunStatus::NeedsReview) {
                throw ValidationException::withMessages(['candidate' => 'Источник кандидата уже устарел. Запустите классификацию повторно.']);
            }

            $target = $targetTagId !== null ? $this->targetTag($candidate->taxonomy, (int) $organization->getKey(), $targetTagId) : null;
            if ($decision === 'merge' && $target === null) {
                throw ValidationException::withMessages(['candidate' => 'Для переименования или объединения выберите существующую категорию.']);
            }
            $reviewedLabel = $label !== null ? trim($label) : null;
            if ($decision === 'rename' && ($reviewedLabel === null || $reviewedLabel === '')) {
                throw ValidationException::withMessages(['candidate' => 'Укажите новое название кандидата.']);
            }
            if ($decision === 'accept' && $candidate->candidate_type === 'new') {
                $target = $this->createTag($candidate, $candidate->label);
            } elseif ($decision === 'accept' && $target === null) {
                $target = $this->targetTag($candidate->taxonomy, (int) $organization->getKey(), (int) $candidate->suggested_existing_id);
            } elseif ($decision === 'rename' && $candidate->candidate_type === 'new') {
                $target = $this->createTag($candidate, $reviewedLabel);
            } elseif ($decision === 'rename') {
                $target = $this->targetTag($candidate->taxonomy, (int) $organization->getKey(), (int) $candidate->suggested_existing_id);
            }
            if ($decision !== 'reject' && $target === null) {
                throw ValidationException::withMessages(['candidate' => 'Не удалось определить категорию для применения решения.']);
            }
            $this->updateRunSuggestion(
                $run,
                $candidate,
                $decision === 'reject' ? null : (int) $target->getKey(),
                $decision === 'rename' ? $reviewedLabel : $target?->name,
            );
            $candidate->forceFill([
                'status' => match ($decision) {
                    'accept' => AnalyticsTagCandidateStatus::Accepted,
                    'rename' => AnalyticsTagCandidateStatus::Renamed,
                    'merge' => AnalyticsTagCandidateStatus::Merged,
                    default => AnalyticsTagCandidateStatus::Rejected,
                },
                'suggested_existing_id' => $target?->getKey() ?? $candidate->suggested_existing_id,
                'reviewed_label' => $reviewedLabel,
                'reviewed_by_user_id' => $actor->getKey(),
                'reviewed_at' => now(),
                'version' => (int) $candidate->version + 1,
            ])->save();
            $this->audit->handle($organization, $actor, 'analytics.tag_candidate.reviewed', $candidate::class, (string) $candidate->getKey(), [
                'decision' => $decision,
                'taxonomy' => $candidate->taxonomy,
                'candidate_type' => $candidate->candidate_type,
            ]);

            return $candidate->refresh();
        });
    }

    private function targetTag(string $taxonomy, int $organizationId, int $targetTagId): MedicalProblemTag|FeedbackTheme|TrackerGoalTag
    {
        if ($targetTagId < 1) {
            throw ValidationException::withMessages(['candidate' => 'Выберите существующую активную категорию.']);
        }
        $model = match ($taxonomy) {
            'complaints' => MedicalProblemTag::class,
            'feedback' => FeedbackTheme::class,
            'goals' => TrackerGoalTag::class,
            default => null,
        };
        if ($model === null) {
            throw ValidationException::withMessages(['candidate' => 'Для этого типа кандидата нет категории назначения.']);
        }

        $target = $model::query()
            ->where('organization_id', $organizationId)
            ->whereKey($targetTagId)
            ->where('is_active', true)
            ->first();
        if ($target === null) {
            throw ValidationException::withMessages(['candidate' => 'Выберите существующую активную категорию.']);
        }

        return $target;
    }

    private function createTag(AnalyticsTagCandidate $candidate, string $label): MedicalProblemTag|FeedbackTheme|TrackerGoalTag
    {
        $model = match ($candidate->taxonomy) {
            'complaints' => MedicalProblemTag::class,
            'feedback' => FeedbackTheme::class,
            'goals' => TrackerGoalTag::class,
            default => throw ValidationException::withMessages(['candidate' => 'Новые категории для этого типа не поддерживаются.']),
        };
        $tag = new $model;
        $tag->forceFill([
            'organization_id' => $candidate->organization_id,
            'name' => $label,
            'group_name' => null,
            'is_active' => true,
            'sort_order' => 0,
        ])->save();

        return $tag;
    }

    private function updateRunSuggestion(
        AnalyticsClassificationRun $run,
        AnalyticsTagCandidate $candidate,
        ?int $targetId,
        ?string $targetLabel,
    ): void {
        $suggestions = is_array($run->suggestions) ? $run->suggestions : [];
        $updated = [];
        foreach ($suggestions as $suggestion) {
            $suggestionId = filter_var($suggestion['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $matches = $candidate->candidate_type === 'new'
                ? $suggestionId === false && $suggestion['label'] === $candidate->label
                : $suggestionId !== false && (int) $suggestionId === (int) $candidate->suggested_existing_id;
            if (! $matches) {
                $updated[] = $suggestion;

                continue;
            }
            if ($targetId !== null) {
                $updated[] = ['id' => $targetId, 'label' => $targetLabel ?? $candidate->label];
            }
        }
        $run->forceFill(['suggestions' => $updated, 'updated_at' => now()])->save();
    }
}
