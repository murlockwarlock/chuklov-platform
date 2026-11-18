<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\AI\Application\Actions\ReviewAiRun;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\HumanReviewDecision;
use App\Modules\AI\Domain\Enums\HumanReviewReasonCode;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunHumanReview;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Illuminate\Validation\ValidationException;

final readonly class ReviewSessionPostureAiDraft
{
    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private ReviewAiRun $review,
    ) {}

    public function accept(
        User $actor,
        MedicalSession $session,
        Client $client,
        int $runId,
        string $editedOutput,
    ): AiRunHumanReview {
        $editedOutput = trim($editedOutput);
        if ($editedOutput === '') {
            throw ValidationException::withMessages([
                'edited_output' => 'Введите наблюдения перед принятием черновика.',
            ]);
        }
        if (mb_strlen($editedOutput) > MedicalSessionFieldLimits::MaxTextLength) {
            throw ValidationException::withMessages([
                'edited_output' => 'Поле превышает максимальную длину в '.MedicalSessionFieldLimits::MaxTextLength.' символов.',
            ]);
        }

        $this->assertReviewable($actor, $session, $client, $runId);

        return $this->review->handle(
            actor: $actor,
            runId: $runId,
            decision: HumanReviewDecision::EditedAndAccepted,
            safeReasonCode: HumanReviewReasonCode::SpecialistEdited->value,
            editedOutput: $editedOutput,
            targetField: 'posture_observations',
        );
    }

    public function reject(
        User $actor,
        MedicalSession $session,
        Client $client,
        int $runId,
        string $reasonCode,
        ?string $notes = null,
    ): AiRunHumanReview {
        $this->assertReviewable($actor, $session, $client, $runId);

        return $this->review->handle(
            actor: $actor,
            runId: $runId,
            decision: HumanReviewDecision::Rejected,
            safeReasonCode: $reasonCode,
            notes: $notes,
        );
    }

    private function assertReviewable(User $actor, MedicalSession $session, Client $client, int $runId): void
    {
        $organization = $this->authorization->authorizeView($actor, $session, $client);
        $reviewOrganization = $this->review->authorize($actor);
        if ((int) $reviewOrganization->getKey() !== (int) $organization->getKey()) {
            throw ValidationException::withMessages([
                'review' => 'Результат AI недоступен в текущей организации.',
            ]);
        }

        $run = AiRun::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->whereKey($runId)
            ->first();
        if ($run === null
            || $run->capability !== AiCapability::PostureAnalysis
            || $run->workflow_key !== 'posture_analysis_session'
            || $run->status !== AiRunStatus::Succeeded
            || $run->human_review_status !== HumanReviewStatus::PendingReview
            || ! collect((array) $run->input_references)->contains(static fn (mixed $reference): bool => is_array($reference)
                && ($reference['type'] ?? null) === 'medical_session'
                && (int) ($reference['id'] ?? 0) === (int) $session->getKey())) {
            throw ValidationException::withMessages([
                'run' => 'Черновик анализа осанки не найден в этом сеансе или уже оценён.',
            ]);
        }

        $payloadExists = AiRunPayload::query()
            ->where('organization_id', $organization->getKey())
            ->where('ai_run_id', $run->getKey())
            ->where(function ($query): void {
                $query
                    ->whereNotNull('encrypted_output_text')
                    ->orWhereNotNull('encrypted_output_payload');
            })
            ->exists();
        if (! $payloadExists) {
            throw ValidationException::withMessages([
                'run' => 'Черновик не содержит доступного результата. Повторите анализ.',
            ]);
        }
    }
}
