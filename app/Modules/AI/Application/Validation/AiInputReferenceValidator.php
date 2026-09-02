<?php

namespace App\Modules\AI\Application\Validation;

use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Registry\AiCapabilityRegistry;
use App\Modules\AI\Domain\ValueObjects\AiInputReference;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use InvalidArgumentException;

final class AiInputReferenceValidator
{
    /** @param list<mixed> $references */
    public function validate(
        int $organizationId,
        AiCapability $capability,
        array $references,
        ?int $clientId = null,
    ): void {
        $definition = AiCapabilityRegistry::get($capability);
        $relatedClientIds = [];

        if ($clientId !== null) {
            $this->findClient($organizationId, $clientId);
            $relatedClientIds[] = $clientId;
        }

        foreach ($references as $reference) {
            if (! $reference instanceof AiInputReference) {
                throw new InvalidArgumentException('AI input references must use the typed AiInputReference value object.');
            }

            if (! in_array($reference->type, AiInputReference::ALLOWED_TYPES, true)
                || ! in_array($reference->type, $definition->allowedInputReferenceTypes, true)) {
                throw new InvalidArgumentException("Input reference type '{$reference->type}' is not allowed for capability '{$capability->value}'.");
            }

            $referenceClientId = match ($reference->type) {
                'client' => $this->findClient($organizationId, $reference->id)->id,
                'companion_attachment' => $this->findCompanionAttachment($organizationId, $reference->id)->client_id,
                'medical_session' => $this->findMedicalSession($organizationId, $reference->id)->client_id,
                'medical_attachment' => $this->findMedicalAttachment($organizationId, $reference->id)->client_id,
                'survey_attempt' => $this->findSurveyAttempt($organizationId, $reference->id)->client_id,
                'booking' => $this->findBooking($organizationId, $reference->id)->client_id,
                'knowledge_source' => $this->validateKnowledgeSource($organizationId, $reference->id),
            };

            if ($referenceClientId !== null) {
                $relatedClientIds[] = (int) $referenceClientId;
            }
        }

        $relatedClientIds = array_values(array_unique(array_map('intval', $relatedClientIds)));
        if (count($relatedClientIds) > 1) {
            throw new InvalidArgumentException('All client-linked AI input references must belong to the same client context.');
        }

        if ($clientId !== null && $relatedClientIds !== [] && $relatedClientIds[0] !== $clientId) {
            throw new InvalidArgumentException('AI input references do not belong to the requested client context.');
        }
    }

    private function findClient(int $organizationId, int $id): Client
    {
        return Client::query()
            ->where('organization_id', $organizationId)
            ->whereKey($id)
            ->first()
            ?? throw new InvalidArgumentException('AI client input reference was not found in the current organization.');
    }

    private function findMedicalSession(int $organizationId, int $id): MedicalSession
    {
        return MedicalSession::query()
            ->where('organization_id', $organizationId)
            ->whereKey($id)
            ->first()
            ?? throw new InvalidArgumentException('AI medical session input reference was not found in the current organization.');
    }

    private function findMedicalAttachment(int $organizationId, int $id): MedicalAttachment
    {
        $attachment = MedicalAttachment::query()
            ->where('organization_id', $organizationId)
            ->whereKey($id)
            ->first()
            ?? throw new InvalidArgumentException('AI medical attachment input reference was not found in the current organization.');

        return $attachment;
    }

    private function findCompanionAttachment(int $organizationId, int $id): MedicalAttachment
    {
        $attachment = MedicalAttachment::query()
            ->where('organization_id', $organizationId)
            ->whereKey($id)
            ->where('attachment_type', AttachmentType::CompanionImage->value)
            ->first()
            ?? throw new InvalidArgumentException('AI Companion image reference was not found in the current organization.');

        return $attachment;
    }

    private function findSurveyAttempt(int $organizationId, int $id): SurveyAttempt
    {
        return SurveyAttempt::query()
            ->where('organization_id', $organizationId)
            ->whereKey($id)
            ->first()
            ?? throw new InvalidArgumentException('AI survey attempt input reference was not found in the current organization.');
    }

    private function findBooking(int $organizationId, int $id): Booking
    {
        return Booking::query()
            ->where('organization_id', $organizationId)
            ->whereKey($id)
            ->first()
            ?? throw new InvalidArgumentException('AI booking input reference was not found in the current organization.');
    }

    private function findKnowledgeSource(int $organizationId, int $id): KnowledgeSource
    {
        return KnowledgeSource::query()
            ->where('organization_id', $organizationId)
            ->whereKey($id)
            ->first()
            ?? throw new InvalidArgumentException('AI knowledge source input reference was not found in the current organization.');
    }

    private function validateKnowledgeSource(int $organizationId, int $id): null
    {
        $this->findKnowledgeSource($organizationId, $id);

        return null;
    }
}
