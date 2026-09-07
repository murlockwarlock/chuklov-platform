<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Attachments\AiAttachmentResolver;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\ValueObjects\AiInputReference;
use App\Modules\Attachments\Application\AttachmentAuthorization;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class StartClinicalDocumentAnalysis
{
    public function __construct(
        private OrganizationContext $context,
        private AttachmentAuthorization $authorization,
        private AiAttachmentResolver $attachmentResolver,
        private DispatchAsyncAiRun $dispatcher,
    ) {}

    public function handle(User $actor, MedicalAttachment $attachment, bool $rerun = false): AiRun
    {
        $organization = $this->context->organization();
        $this->authorization->authorizeAiProcessing($actor, $attachment, $organization);

        if ($attachment->attachment_type !== AttachmentType::MedicalReport
            || $attachment->client_id === null
            || $attachment->evaluation_fixture_key !== null) {
            throw new InvalidArgumentException('Анализ документов доступен только для медицинского заключения клиента.');
        }

        $reference = new AiInputReference('medical_attachment', (int) $attachment->getKey());
        $this->attachmentResolver->describe(
            organizationId: (int) $organization->getKey(),
            capability: AiCapability::ClinicalDocumentExtraction,
            references: [$reference],
            actor: $actor,
            clientId: (int) $attachment->client_id,
        );

        $baseKey = 'clinical_document_extraction:'.hash('sha256', (string) $attachment->getKey().'|'.$attachment->sha256_checksum);
        $idempotencyKey = $this->idempotencyKey(
            organizationId: (int) $organization->getKey(),
            baseKey: $baseKey,
            rerun: $rerun,
        );

        $requiredModality = in_array(strtolower((string) $attachment->mime_type), ['image/jpeg', 'image/png', 'image/webp'], true)
            ? AiModelModality::ImageInput
            : AiModelModality::DocumentInput;

        return $this->dispatcher->handle($actor, new AiRunRequest(
            capability: AiCapability::ClinicalDocumentExtraction,
            workflowKey: 'clinical_document_extraction',
            origin: AiRunOrigin::User,
            executionMode: AiExecutionMode::Async,
            clientId: (int) $attachment->client_id,
            inputVariables: [
                'document_text' => 'См. вложенный медицинский документ.',
            ],
            inputReferences: [
                new AiInputReference('client', (int) $attachment->client_id),
                $reference,
            ],
            requiredModalities: [$requiredModality],
            idempotencyKey: $idempotencyKey,
            actor: $actor,
        ));
    }

    private function idempotencyKey(int $organizationId, string $baseKey, bool $rerun): string
    {
        if ($rerun) {
            return $baseKey.':rerun:'.substr(hash('sha256', (string) Str::uuid()), 0, 16);
        }

        $failedRun = AiRun::query()
            ->where('organization_id', $organizationId)
            ->where('idempotency_key', $baseKey)
            ->whereIn('status', ['failed', 'timed_out', 'invalid_output', 'cancelled'])
            ->first();

        return $failedRun === null ? $baseKey : $baseKey.':retry:'.$failedRun->getKey();
    }
}
