<?php

namespace App\Modules\Attachments\Application;

use App\Models\User;
use App\Modules\Attachments\Application\DTOs\AttachmentUploadCommand;
use App\Modules\Attachments\Domain\Contracts\AttachmentStorageInterface;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Attachments\Domain\ValueObjects\StoredAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class UploadMedicalAttachment
{
    private const MAX_MEDICAL_REPORTS_PER_CLIENT = 5;

    public function __construct(
        private AttachmentAuthorization $authorization,
        private AttachmentStorageInterface $storage,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User|Client $actor, AttachmentUploadCommand $command): MedicalAttachment
    {
        $organization = $this->authorization->organization();
        $client = Client::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($command->clientId)
            ->firstOrFail();

        $this->authorization->authorizeUpload($actor, $client, $command->attachmentType);
        $orgId = (int) $organization->getKey();

        if ($command->uploadKey !== null && ! Str::isUuid($command->uploadKey)) {
            throw ValidationException::withMessages(['upload_key' => 'Invalid upload key.']);
        }
        $uuid = $command->uploadKey ?? (string) Str::uuid();
        $stored = null;
        try {
            $stored = $this->storage->store($orgId, $command->file, (string) Str::uuid());
            $attachment = DB::transaction(function () use ($actor, $organization, $client, $command, $uuid, $stored): MedicalAttachment {
                $lockedClient = Client::query()->where('organization_id', $organization->getKey())->whereKey($client->getKey())->lockForUpdate()->firstOrFail();
                $this->authorization->authorizeUpload($actor, $lockedClient, $command->attachmentType);
                $existing = MedicalAttachment::query()->where('organization_id', $organization->getKey())->where('client_id', $client->getKey())->where('uuid', $uuid)->first();
                if ($existing !== null) {
                    if ((int) $existing->organization_id !== (int) $organization->getKey()
                        || (int) $existing->client_id !== (int) $client->getKey()
                        || $existing->attachment_type !== $command->attachmentType
                        || $existing->sha256_checksum !== $stored->sha256Checksum
                        || $existing->original_filename !== $stored->originalFilename) {
                        throw ValidationException::withMessages(['upload_key' => 'This upload key cannot be reused.']);
                    }

                    return $existing;
                }
                if ($command->attachmentType === AttachmentType::MedicalReport) {
                    $medicalReportCount = MedicalAttachment::query()
                        ->where('organization_id', $organization->getKey())
                        ->where('client_id', $client->getKey())
                        ->where('attachment_type', AttachmentType::MedicalReport)
                        ->count();
                    if ($medicalReportCount >= self::MAX_MEDICAL_REPORTS_PER_CLIENT) {
                        throw ValidationException::withMessages([
                            'file_count' => __('В медицинском профиле можно загрузить не более пяти файлов.'),
                        ]);
                    }
                }
                $attachment = new MedicalAttachment;
                $attachment->forceFill([
                    'uuid' => $uuid,
                    'organization_id' => $organization->getKey(),
                    'client_id' => $client->getKey(),
                    'uploaded_by_user_id' => $actor instanceof User ? $actor->getKey() : null,
                    'attachment_type' => $command->attachmentType,
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
                    actor: $actor instanceof User ? $actor : null,
                    action: 'attachment.uploaded',
                    targetType: MedicalAttachment::class,
                    targetId: (string) $attachment->getKey(),
                    metadata: [
                        'source' => $actor instanceof User ? 'crm' : 'portal',
                        'actor_type' => $actor instanceof Client ? 'client' : 'user',
                        'actor_client_id' => $actor instanceof Client ? $actor->getKey() : null,
                        'attachment_type' => $command->attachmentType->value,
                        'mime_type' => $stored->mimeType,
                        'size_bytes' => $stored->sizeBytes,
                    ],
                );

                return $attachment;
            });
            if ($attachment->storage_path !== $stored->storagePath) {
                $this->storage->delete($stored->storagePath);
            }

            return $attachment;
        } catch (\Throwable $exception) {
            if ($stored instanceof StoredAttachment) {
                $this->storage->delete($stored->storagePath);
            }
            if ($exception instanceof UniqueConstraintViolationException && $command->uploadKey !== null) {
                throw ValidationException::withMessages(['upload_key' => 'This upload key cannot be reused.']);
            }

            throw $exception;
        }
    }
}
