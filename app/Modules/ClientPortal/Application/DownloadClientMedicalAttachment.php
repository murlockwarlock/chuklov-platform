<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Attachments\Application\DTOs\AttachmentDownloadResult;
use App\Modules\Attachments\Domain\Contracts\AttachmentStorageInterface;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Security\Application\RecordAuditEvent;

final readonly class DownloadClientMedicalAttachment
{
    public function __construct(
        private GetClientTemporaryAttachmentUrl $urls,
        private AttachmentStorageInterface $storage,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(MedicalAttachment $attachment): AttachmentDownloadResult
    {
        $this->urls->assertAccessible($attachment);
        $stream = $this->storage->readStream($attachment->storage_path);

        $this->audit->handle(
            organization: $attachment->organization()->firstOrFail(),
            actor: null,
            action: 'attachment.downloaded',
            targetType: MedicalAttachment::class,
            targetId: (string) $attachment->getKey(),
            metadata: ['source' => 'portal'],
        );

        return new AttachmentDownloadResult(
            stream: $stream,
            filename: $attachment->original_filename,
            mimeType: $attachment->mime_type,
            sizeBytes: $attachment->size_bytes,
        );
    }
}
