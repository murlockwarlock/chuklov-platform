<?php

namespace App\Modules\AI\Application\Services;

use App\Models\User;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Sessions\Application\MedicalSessionAuthorization;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListClinicalSessionDocumentAiRuns
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private MedicalSessionAuthorization $sessionAuthorization,
    ) {}

    /** @return Collection<int, AiRun> */
    public function handle(User $actor, MedicalSession $session, Client $client): Collection
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewAiRuns);
        $this->sessionAuthorization->authorizeView($actor, $session, $client);
        $this->sessionAuthorization->assertClientOwned($client, $organization);

        $runs = AiRun::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->where('capability', AiCapability::ClinicalDocumentExtraction)
            ->where('workflow_key', 'clinical_document_extraction')
            ->whereRaw(
                'input_references::jsonb @> ?::jsonb',
                [json_encode([['type' => 'medical_session', 'id' => (int) $session->getKey()]], JSON_THROW_ON_ERROR)],
            )
            ->whereRaw(
                'EXISTS (
                    SELECT 1
                    FROM medical_attachments AS attachment
                    CROSS JOIN LATERAL jsonb_array_elements(ai_runs.input_references::jsonb) AS reference
                    WHERE attachment.organization_id = ?
                      AND attachment.client_id = ?
                      AND attachment.attachment_type = ?
                      AND attachment.evaluation_fixture_key IS NULL
                      AND reference->>\'type\' = \'medical_attachment\'
                      AND reference->>\'id\' = attachment.id::text
                )',
                [
                    $organization->getKey(),
                    $client->getKey(),
                    AttachmentType::MedicalReport->value,
                ],
            )
            ->with(['humanReviews.reviewer', 'promptVersion', 'modelRelease'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->filter(fn (AiRun $run): bool => $this->referencesCurrentSession($run, (int) $session->getKey()))
            ->values();

        $attachmentIds = $runs
            ->flatMap(fn (AiRun $run): array => $this->documentReferenceIds($run))
            ->unique()
            ->values();
        $validAttachmentIds = MedicalAttachment::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->where('attachment_type', AttachmentType::MedicalReport)
            ->whereNull('evaluation_fixture_key')
            ->whereIn('id', $attachmentIds->all())
            ->pluck('id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();

        return $runs
            ->filter(fn (AiRun $run): bool => array_intersect($this->documentReferenceIds($run), $validAttachmentIds) !== [])
            ->values();
    }

    private function referencesCurrentSession(AiRun $run, int $sessionId): bool
    {
        return collect((array) $run->input_references)->contains(static fn (mixed $reference): bool => is_array($reference)
                && ($reference['type'] ?? null) === 'medical_session'
                && (int) ($reference['id'] ?? 0) === $sessionId);
    }

    /** @return list<int> */
    private function documentReferenceIds(AiRun $run): array
    {
        return collect((array) $run->input_references)
            ->filter(static fn (mixed $reference): bool => is_array($reference) && ($reference['type'] ?? null) === 'medical_attachment')
            ->map(static fn (array $reference): int => (int) ($reference['id'] ?? 0))
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
