<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\AI\Application\Actions\ReviewAiRun;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\ClinicalSynthesizerWorkflow;
use App\Modules\AI\Domain\Enums\HumanReviewDecision;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Illuminate\Validation\ValidationException;

final readonly class RejectClinicalSessionAiDraft
{
    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private ReviewAiRun $review,
    ) {}

    public function handle(
        User $actor,
        MedicalSession $session,
        Client $client,
        int $runId,
        string $reasonCode,
        ?string $notes = null,
    ): void {
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
            || $run->capability !== AiCapability::ClinicalSynthesizer
            || $run->workflow_key !== ClinicalSynthesizerWorkflow::SessionDraft->value
            || $run->status !== AiRunStatus::Succeeded
            || ! collect((array) $run->input_references)->contains(static fn (mixed $reference): bool => is_array($reference)
                && ($reference['type'] ?? null) === 'medical_session'
                && (int) ($reference['id'] ?? 0) === (int) $session->getKey())) {
            throw ValidationException::withMessages([
                'run' => 'Черновик AI не найден в этом сеансе.',
            ]);
        }

        $this->review->handle(
            actor: $actor,
            runId: (int) $run->getKey(),
            decision: HumanReviewDecision::Rejected,
            safeReasonCode: $reasonCode,
            notes: $notes,
        );
    }
}
