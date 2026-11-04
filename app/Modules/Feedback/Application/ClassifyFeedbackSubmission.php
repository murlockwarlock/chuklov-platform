<?php

namespace App\Modules\Feedback\Application;

use App\Models\User;
use App\Modules\Feedback\Domain\Models\FeedbackSubmission;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;

final readonly class ClassifyFeedbackSubmission
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private FeedbackThemeCatalog $themes,
        private RecordAuditEvent $audit,
    ) {}

    /** @param array<int, mixed> $themeIds */
    public function handle(User $actor, FeedbackSubmission $submission, array $themeIds): FeedbackSubmission
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);
        abort_unless((int) $submission->organization_id === (int) $organization->getKey(), 404);

        $preservedThemeIds = $submission->themes()->pluck('feedback_themes.id')->all();
        $themeIds = $this->themes->assertKnown((int) $organization->getKey(), $themeIds, $preservedThemeIds);

        return DB::transaction(function () use ($organization, $actor, $submission, $themeIds): FeedbackSubmission {
            $locked = FeedbackSubmission::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($submission->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $locked->themes()->syncWithPivotValues($themeIds, [
                'organization_id' => $organization->getKey(),
                'client_id' => $locked->client_id,
            ]);
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'feedback.classification.updated',
                targetType: FeedbackSubmission::class,
                targetId: (string) $locked->getKey(),
                metadata: ['theme_count' => count($themeIds)],
            );

            return $locked->load('themes');
        });
    }
}
