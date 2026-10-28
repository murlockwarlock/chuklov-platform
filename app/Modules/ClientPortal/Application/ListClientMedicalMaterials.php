<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Application\ResolvePatientClinicalClient;

final readonly class ListClientMedicalMaterials
{
    public function __construct(private ClientPortalContext $context, private GetClientTemporaryAttachmentUrl $urls, private ResolvePatientClinicalClient $patients) {}

    /** @return array<int, array<string, mixed>> */
    public function handle(?int $patientId = null): array
    {
        $client = $this->patients->handle($this->context->client(), $patientId);

        $materials = MedicalAttachment::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->whereIn('attachment_type', [AttachmentType::MedicalReport->value, AttachmentType::PosturePhoto->value])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(100)
            ->get(['id', 'uuid', 'organization_id', 'client_id', 'attachment_type', 'original_filename', 'mime_type', 'size_bytes', 'created_at']);
        $urls = $this->urls->forClientMaterials($client, $materials);

        return $materials->map(fn (MedicalAttachment $attachment): array => [
            'id' => (int) $attachment->getKey(), 'type' => $attachment->attachment_type->value,
            'filename' => $attachment->original_filename, 'mimeType' => $attachment->mime_type,
            'sizeBytes' => (int) $attachment->size_bytes, 'createdAt' => $attachment->created_at->toIso8601String(),
            ...$urls[(int) $attachment->getKey()],
        ])->values()->all();
    }
}
