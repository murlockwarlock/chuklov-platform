<?php

namespace App\Modules\Attachments\Application;

use App\Models\User;
use App\Modules\Attachments\Application\DTOs\AttachmentDownloadResult;
use App\Modules\Attachments\Domain\Contracts\AttachmentStorageInterface;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Security\Application\RecordAuditEvent;

final readonly class DownloadMedicalAttachment
{
    public function __construct(
        private AttachmentAuthorization $authorization,
        private AttachmentStorageInterface $storage,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, MedicalAttachment $attachment): AttachmentDownloadResult
    {
        $organization = $this->authorization->authorizeDownload($actor, $attachment);

        $stream = $this->storage->readStream($attachment->storage_path);

        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: 'attachment.downloaded',
            targetType: MedicalAttachment::class,
            targetId: (string) $attachment->getKey(),
            metadata: [
                'source' => 'crm',
            ],
        );

        return new AttachmentDownloadResult(
            stream: $stream,
            filename: $attachment->original_filename,
            mimeType: $attachment->mime_type,
            sizeBytes: $attachment->size_bytes,
        );
    }
}
