<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Attachments\Application\GetTemporaryAttachmentUrl;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Sessions\Application\DTOs\SessionAttachmentData;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionAttachment;

final readonly class ListSessionAttachments
{
    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private GetTemporaryAttachmentUrl $temporaryUrl,
    ) {}

    /** @return list<SessionAttachmentData> */
    public function handle(User $actor, MedicalSession $session, Client $client, int $limit = 50): array
    {
        $organization = $this->authorization->authorizeView($actor, $session, $client);

        $attachments = MedicalSessionAttachment::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->where('medical_session_id', $session->getKey())
            ->with('attachment:id,organization_id,client_id,uuid,attachment_type,original_filename,size_bytes')
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 50)))
            ->get(['id', 'organization_id', 'client_id', 'medical_session_id', 'medical_attachment_id'])
            ->map(function (MedicalSessionAttachment $link) use ($actor): SessionAttachmentData {
                $attachment = $link->attachment;

                return new SessionAttachmentData(
                    attachmentId: (int) $attachment->getKey(),
                    filename: $attachment->original_filename,
                    type: $attachment->attachment_type->label(),
                    sizeBytes: (int) $attachment->size_bytes,
                    downloadUrl: $this->temporaryUrl->handle($actor, $attachment, 15),
                );
            })
            ->all();

        return array_values($attachments);
    }
}
