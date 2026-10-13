<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Attachments\Application\GetTemporaryAttachmentUrl;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;

final readonly class ListClientMedicalMaterials
{
    public function __construct(private ClientPortalContext $context, private GetClientTemporaryAttachmentUrl $urls) {}

    /** @return list<array<string, mixed>> */
    public function handle(): array
    {
        $client = $this->context->client();

        return MedicalAttachment::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->whereIn('attachment_type', [AttachmentType::MedicalReport->value, AttachmentType::PosturePhoto->value])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(100)
            ->get(['id', 'uuid', 'organization_id', 'client_id', 'attachment_type', 'original_filename', 'mime_type', 'size_bytes', 'created_at'])
            ->map(fn (MedicalAttachment $attachment): array => [
                'id' => (int) $attachment->getKey(), 'type' => $attachment->attachment_type->value,
                'filename' => $attachment->original_filename, 'mimeType' => $attachment->mime_type,
                'sizeBytes' => (int) $attachment->size_bytes, 'createdAt' => $attachment->created_at->toIso8601String(),
                'downloadUrl' => $this->urls->handle($attachment),
                'previewUrl' => GetTemporaryAttachmentUrl::supportsPreview($attachment->mime_type) ? $this->urls->handle($attachment, mode: 'preview') : null,
            ])->values()->all();
    }
}
