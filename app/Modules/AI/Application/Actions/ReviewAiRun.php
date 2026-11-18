<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\HumanReviewDecision;
use App\Modules\AI\Domain\Enums\HumanReviewReasonCode;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunHumanReview;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ReviewAiRun
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
        private readonly MedicalEncryptorInterface $medicalEncryptor,
    ) {}

    public function authorize(User $actor): Organization
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ReviewAiProposals);

        return $organization;
    }

    public function handle(
        User $actor,
        int $runId,
        HumanReviewDecision $decision,
        ?string $safeReasonCode = null,
        ?string $notes = null,
        ?string $editedOutput = null,
        ?string $targetField = null,
    ): AiRunHumanReview {
        $organization = $this->authorize($actor);
        $reasonCode = $this->reasonCode($safeReasonCode);
        $this->validateTargetField($targetField);

        $run = AiRun::query()
            ->where('organization_id', $organization->getKey())
            ->where('id', $runId)
            ->first();

        if ($run === null) {
            throw new InvalidArgumentException('AI Run not found.');
        }
        if ($run->status !== AiRunStatus::Succeeded) {
            throw ValidationException::withMessages([
                'review' => 'Оценить можно только успешно завершённый результат AI.',
            ]);
        }

        $keyVersion = (int) Config::get('medical.key_version', 1);

        return DB::transaction(function () use ($organization, $actor, $run, $decision, $reasonCode, $notes, $editedOutput, $targetField, $keyVersion): AiRunHumanReview {
            $lockedRun = AiRun::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($run->id)
                ->lockForUpdate()
                ->first();

            if ($lockedRun === null) {
                throw new InvalidArgumentException('AI Run not found.');
            }

            return $this->handleWithinTransaction(
                organization: $organization,
                actor: $actor,
                lockedRun: $lockedRun,
                decision: $decision,
                reasonCode: $reasonCode,
                notes: $notes,
                editedOutput: $editedOutput,
                targetField: $targetField,
                keyVersion: $keyVersion,
            );
        });
    }

    public function handleWithinTransaction(
        Organization $organization,
        User $actor,
        AiRun $lockedRun,
        HumanReviewDecision $decision,
        ?HumanReviewReasonCode $reasonCode = null,
        ?string $notes = null,
        ?string $editedOutput = null,
        ?string $targetField = null,
        ?int $keyVersion = null,
    ): AiRunHumanReview {
        if ((int) $lockedRun->organization_id !== (int) $organization->getKey()) {
            throw new InvalidArgumentException('AI Run is outside the current organization.');
        }
        if ($lockedRun->status !== AiRunStatus::Succeeded) {
            throw ValidationException::withMessages([
                'review' => 'Оценить можно только успешно завершённый результат AI.',
            ]);
        }
        $this->validateTargetField($targetField);

        if (in_array($lockedRun->human_review_status, [
            HumanReviewStatus::Accepted,
            HumanReviewStatus::Rejected,
            HumanReviewStatus::EditedAndAccepted,
        ], true)) {
            throw ValidationException::withMessages([
                'review' => 'Этот результат AI уже оценён специалистом.',
            ]);
        }

        $latestStep = AiRunHumanReview::query()
            ->where('organization_id', $organization->getKey())
            ->where('ai_run_id', $lockedRun->id)
            ->max('review_step') ?? 0;

        $review = new AiRunHumanReview([
            'organization_id' => $organization->getKey(),
            'ai_run_id' => $lockedRun->id,
            'review_step' => $latestStep + 1,
            'decision' => $decision,
            'reviewer_user_id' => $actor->getKey(),
            'safe_reason_code' => $reasonCode?->value,
            'target_field' => $targetField,
            'reviewed_at' => Carbon::now(),
        ]);
        $review->save();

        $newStatus = match ($decision) {
            HumanReviewDecision::Accepted => HumanReviewStatus::Accepted,
            HumanReviewDecision::Rejected => HumanReviewStatus::Rejected,
            HumanReviewDecision::EditedAndAccepted => HumanReviewStatus::EditedAndAccepted,
        };

        $lockedRun->update([
            'human_review_status' => $newStatus,
        ]);

        if ($notes !== null || $editedOutput !== null) {
            $payload = AiRunPayload::query()
                ->where('organization_id', $organization->getKey())
                ->where('ai_run_id', $lockedRun->id)
                ->first();

            if ($payload !== null) {
                $updates = [];
                $resolvedKeyVersion = $keyVersion ?? (int) Config::get('medical.key_version', 1);
                if ($notes !== null) {
                    $updates['encrypted_human_review_notes'] = $this->medicalEncryptor->encryptField(
                        $organization->getKey(),
                        $notes,
                        $resolvedKeyVersion,
                    );
                }
                if ($editedOutput !== null) {
                    $updates['encrypted_human_edited_output'] = $this->medicalEncryptor->encryptField(
                        $organization->getKey(),
                        $editedOutput,
                        $resolvedKeyVersion,
                    );
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
                'ai_run_id' => (string) $lockedRun->id,
                'decision' => $decision->value,
                'safe_reason_code' => $reasonCode?->value,
                'target_field' => $targetField,
            ],
        );

        return $review;
    }

    private function reasonCode(?string $safeReasonCode): ?HumanReviewReasonCode
    {
        $reasonCode = $safeReasonCode === null
            ? null
            : HumanReviewReasonCode::tryFrom($safeReasonCode);
        if ($safeReasonCode !== null && $reasonCode === null) {
            throw new InvalidArgumentException('Unsupported human review reason code.');
        }

        return $reasonCode;
    }

    private function validateTargetField(?string $targetField): void
    {
        if ($targetField !== null && preg_match('/\A[a-z_]{1,64}\z/D', $targetField) !== 1) {
            throw new InvalidArgumentException('Unsupported AI review target field.');
        }
    }
}
