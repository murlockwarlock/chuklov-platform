<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Enums\HumanReviewDecision;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunHumanReview;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReviewAiRun
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
        private readonly MedicalEncryptorInterface $medicalEncryptor,
    ) {}

    public function handle(
        User $actor,
        int $runId,
        HumanReviewDecision $decision,
        ?string $safeReasonCode = null,
        ?string $notes = null,
        ?string $editedOutput = null,
    ): AiRunHumanReview {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ReviewAiProposals);

        $run = AiRun::query()
            ->where('organization_id', $organization->getKey())
            ->where('id', $runId)
            ->first();

        if ($run === null) {
            throw new InvalidArgumentException('AI Run not found.');
        }

        $keyVersion = (int) Config::get('medical.key_version', 1);

        return DB::transaction(function () use ($organization, $actor, $run, $decision, $safeReasonCode, $notes, $editedOutput, $keyVersion) {
            $latestStep = AiRunHumanReview::query()
                ->where('organization_id', $organization->getKey())
                ->where('ai_run_id', $run->id)
                ->max('review_step') ?? 0;

            $review = new AiRunHumanReview([
                'organization_id' => $organization->getKey(),
                'ai_run_id' => $run->id,
                'review_step' => $latestStep + 1,
                'decision' => $decision,
                'reviewer_user_id' => $actor->getKey(),
                'safe_reason_code' => $safeReasonCode,
                'reviewed_at' => Carbon::now(),
            ]);
            $review->save();

            $newStatus = match ($decision) {
                HumanReviewDecision::Accepted => HumanReviewStatus::Accepted,
                HumanReviewDecision::Rejected => HumanReviewStatus::Rejected,
                HumanReviewDecision::EditedAndAccepted => HumanReviewStatus::EditedAndAccepted,
            };

            $run->update([
                'human_review_status' => $newStatus,
            ]);

            if ($notes !== null || $editedOutput !== null) {
                $payload = AiRunPayload::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('ai_run_id', $run->id)
                    ->first();

                if ($payload !== null) {
                    $updates = [];
                    if ($notes !== null) {
                        $updates['encrypted_human_review_notes'] = $this->medicalEncryptor->encryptField($organization->getKey(), $notes, $keyVersion);
                    }
                    if ($editedOutput !== null) {
                        $updates['encrypted_human_edited_output'] = $this->medicalEncryptor->encryptField($organization->getKey(), $editedOutput, $keyVersion);
                    }
                    $payload->update($updates);
                }
            }

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'ai.human_review.submitted',
                targetType: AiRunHumanReview::class,
                targetId: (string) $review->id,
                metadata: [
                    'ai_run_id' => (string) $run->id,
                    'decision' => $decision->value,
                    'safe_reason_code' => $safeReasonCode,
                ],
            );

            return $review;
        });
    }
}
