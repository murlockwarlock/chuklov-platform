<?php

namespace App\Modules\Attachments\Application;

use App\Models\User;
use App\Modules\Attachments\Application\DTOs\AttachmentUploadCommand;
use App\Modules\Attachments\Domain\Contracts\AttachmentScannerInterface;
use App\Modules\Attachments\Domain\Contracts\AttachmentStorageInterface;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class UploadMedicalAttachment
{
    public function __construct(
        private AttachmentAuthorization $authorization,
        private AttachmentStorageInterface $storage,
        private AttachmentScannerInterface $scanner,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, AttachmentUploadCommand $command): MedicalAttachment
    {
        $organization = $this->authorization->organization();
        $client = Client::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($command->clientId)
            ->firstOrFail();

        $this->authorization->authorizeUpload($actor, $client);
        $orgId = (int) $organization->getKey();

        $uuid = (string) Str::uuid();

        $scanResult = $this->scanner->scan($command->file);
        $stored = $this->storage->store($orgId, $command->file, $uuid);

        return DB::transaction(function () use ($actor, $organization, $client, $command, $uuid, $stored, $scanResult) {
            $attachment = new MedicalAttachment;
            $attachment->forceFill([
                'uuid' => $uuid,
                'organization_id' => $organization->getKey(),
                'client_id' => $client->getKey(),
                'uploaded_by_user_id' => $actor->getKey(),
                'attachment_type' => $command->attachmentType,
                'disk' => $stored->disk,
                'storage_path' => $stored->storagePath,
                'original_filename' => $stored->originalFilename,
                'mime_type' => $stored->mimeType,
                'size_bytes' => $stored->sizeBytes,
                'sha256_checksum' => $stored->sha256Checksum,
                'scan_status' => $scanResult->status,
                'scan_result_metadata' => $scanResult->metadata,
                'scanned_at' => now(),
            ]);
            $attachment->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'attachment.uploaded',
                targetType: MedicalAttachment::class,
                targetId: (string) $attachment->getKey(),
                metadata: [
                    'source' => 'crm',
                    'attachment_type' => $command->attachmentType->value,
                    'mime_type' => $stored->mimeType,
                    'size_bytes' => $stored->sizeBytes,
                    'scan_status' => $scanResult->status->value,
                ],
            );

            return $attachment;
        });
    }
}
