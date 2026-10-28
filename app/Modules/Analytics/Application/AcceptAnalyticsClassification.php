<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Domain\Enums\ClassificationRunStatus;
use App\Modules\Analytics\Domain\Models\AnalyticsClassificationRun;
use App\Modules\Feedback\Application\FeedbackThemeCatalog;
use App\Modules\Feedback\Domain\Models\FeedbackSubmission;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AcceptAnalyticsClassification
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private FeedbackThemeCatalog $themes,
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
            if ($run->taxonomy !== 'feedback' || $run->source_type !== 'feedback_submission') {
                throw ValidationException::withMessages([
                    'classification' => 'Этот тип классификации пока нельзя принять в CRM.',
                ]);
            }

            $submission = FeedbackSubmission::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($run->source_id)
                ->lockForUpdate()
                ->firstOrFail();
            $suggestedIds = $this->suggestedIds($run->suggestions);
            $selectedIds = $themeIds === null ? $suggestedIds : $themeIds;
            $selectedIds = $this->themes->assertKnown((int) $organization->getKey(), $selectedIds);

            $submission->themes()->syncWithPivotValues($selectedIds, [
                'organization_id' => $organization->getKey(),
                'client_id' => $submission->client_id,
            ]);
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
