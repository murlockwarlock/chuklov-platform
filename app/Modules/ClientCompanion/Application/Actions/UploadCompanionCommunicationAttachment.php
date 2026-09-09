<?php

namespace App\Modules\ClientCompanion\Application\Actions;

use App\Models\User;
use App\Modules\Attachments\Domain\Contracts\AttachmentStorageInterface;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Attachments\Domain\ValueObjects\StoredAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class UploadCompanionCommunicationAttachment
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private AttachmentStorageInterface $storage,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Client $client, UploadedFile $file): MedicalAttachment
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageCompanionHandoff);
        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw ValidationException::withMessages(['file' => 'Файл недоступен для этого клиента.']);
        }

        $uuid = (string) Str::uuid();
        $stored = null;

        try {
            $stored = $this->storage->store((int) $organization->getKey(), $file, $uuid);
            $attachmentType = str_starts_with($stored->mimeType, 'image/')
                ? AttachmentType::CompanionImage
                : AttachmentType::CompanionDocument;

            return DB::transaction(function () use ($actor, $organization, $client, $stored, $uuid, $attachmentType): MedicalAttachment {
                $attachment = new MedicalAttachment;
                $attachment->forceFill([
                    'uuid' => $uuid,
                    'organization_id' => $organization->getKey(),
                    'client_id' => $client->getKey(),
                    'uploaded_by_user_id' => $actor->getKey(),
                    'attachment_type' => $attachmentType,
                    'disk' => $stored->disk,
                    'storage_path' => $stored->storagePath,
                    'original_filename' => $stored->originalFilename,
                    'mime_type' => $stored->mimeType,
                    'size_bytes' => $stored->sizeBytes,
                    'sha256_checksum' => $stored->sha256Checksum,
                ]);
                $attachment->save();

                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'attachment.uploaded',
                    targetType: MedicalAttachment::class,
                    targetId: (string) $attachment->getKey(),
                    metadata: [
                        'source' => 'companion_crm',
                        'attachment_type' => $attachmentType->value,
                        'mime_type' => $stored->mimeType,
                        'size_bytes' => $stored->sizeBytes,
                    ],
                );

                return $attachment->refresh();
            });
        } catch (\Throwable $exception) {
            if ($stored instanceof StoredAttachment) {
                $this->storage->delete($stored->storagePath);
            }

            throw $exception;
        }
    }
}
