<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\HumanReviewDecision;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunHumanReview;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Sessions\Application\MedicalSessionAuthorization;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final readonly class ReviewClinicalSessionDocumentAiRun
{
    public function __construct(
        private MedicalSessionAuthorization $sessionAuthorization,
        private ReviewAiRun $review,
    ) {}

    public function handle(
        User $actor,
        Client $client,
        MedicalSession $session,
        int $runId,
        HumanReviewDecision $decision,
        ?string $safeReasonCode = null,
        ?string $notes = null,
        ?string $editedOutput = null,
    ): AiRunHumanReview {
        $organization = $this->sessionAuthorization->authorizeView($actor, $session, $client);
        $this->sessionAuthorization->assertClientOwned($client, $organization);
        $this->review->authorize($actor);

        $run = AiRun::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->whereKey($runId)
            ->where('capability', AiCapability::ClinicalDocumentExtraction)
            ->where('workflow_key', 'clinical_document_extraction')
            ->where('status', AiRunStatus::Succeeded)
            ->where('human_review_status', HumanReviewStatus::PendingReview)
            ->first();

        if (! $run instanceof AiRun || ! $this->referencesSessionDocument($run, $session, $client)) {
            throw new AuthorizationException('Результат AI не относится к текущему медицинскому сеансу и документу.');
        }

        if (! AiRunPayload::query()
            ->where('organization_id', $organization->getKey())
            ->where('ai_run_id', $run->getKey())
            ->exists()) {
            throw ValidationException::withMessages([
                'review' => 'Оригинальный результат AI недоступен для проверки.',
            ]);
        }

        if ($decision === HumanReviewDecision::EditedAndAccepted && ! filled($editedOutput)) {
            throw ValidationException::withMessages([
                'edited_output' => 'Для принятия исправленной версии нужен текст результата.',
            ]);
        }

        return $this->review->handle(
            actor: $actor,
            runId: (int) $run->getKey(),
            decision: $decision,
            safeReasonCode: $safeReasonCode,
            notes: $notes,
            editedOutput: $editedOutput,
        );
    }

    private function referencesSessionDocument(AiRun $run, MedicalSession $session, Client $client): bool
    {
        $references = collect((array) $run->input_references);
        $sessionMatches = $references->contains(static fn (mixed $reference): bool => is_array($reference)
            && ($reference['type'] ?? null) === 'medical_session'
            && (int) ($reference['id'] ?? 0) === (int) $session->getKey());
        $attachmentIds = $references
            ->filter(static fn (mixed $reference): bool => is_array($reference) && ($reference['type'] ?? null) === 'medical_attachment')
            ->map(static fn (array $reference): int => (int) ($reference['id'] ?? 0))
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if (! $sessionMatches || $attachmentIds->count() !== 1) {
            return false;
        }

        return MedicalAttachment::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->where('attachment_type', AttachmentType::MedicalReport)
            ->whereNull('evaluation_fixture_key')
            ->whereKey($attachmentIds->first())
            ->exists();
    }
}
