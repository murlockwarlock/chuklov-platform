<?php

namespace App\Modules\ClientCompanion\Application\Actions;

use App\Modules\Attachments\Domain\Contracts\AttachmentStorageInterface;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Attachments\Domain\ValueObjects\StoredAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class UploadCompanionDocument
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly AttachmentStorageInterface $storage,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(Client $client, UploadedFile $file): MedicalAttachment
    {
        $organization = $this->context->organization();
        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw ValidationException::withMessages(['document' => 'Документ недоступен для этого аккаунта.']);
        }

        $this->assertPdf($file);
        $uuid = (string) Str::uuid();
        $stored = null;

        try {
            $stored = $this->storage->store((int) $organization->getKey(), $file, $uuid);
            if ($stored->mimeType !== 'application/pdf') {
                throw ValidationException::withMessages(['document' => 'Поддерживаются только PDF-документы.']);
            }

            return DB::transaction(function () use ($organization, $client, $stored, $uuid): MedicalAttachment {
                $attachment = new MedicalAttachment;
                $attachment->forceFill([
                    'uuid' => $uuid,
                    'organization_id' => $organization->getKey(),
                    'client_id' => $client->getKey(),
                    'uploaded_by_user_id' => null,
                    'attachment_type' => AttachmentType::CompanionDocument,
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
                    actor: null,
                    action: 'attachment.uploaded',
                    targetType: MedicalAttachment::class,
                    targetId: (string) $attachment->getKey(),
                    metadata: [
                        'source' => 'client_companion',
                        'attachment_type' => AttachmentType::CompanionDocument->value,
                        'mime_type' => $attachment->mime_type,
                        'size_bytes' => $attachment->size_bytes,
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

    private function assertPdf(UploadedFile $file): void
    {
        $size = $file->getSize();
        if (! $file->isValid() || $size === false || $size <= 0 || $size > (int) config('medical.attachment_max_bytes', 20_971_520)) {
            throw ValidationException::withMessages(['document' => 'Документ слишком большой или повреждён.']);
        }

        if (strtolower((string) $file->getMimeType()) !== 'application/pdf'
            || strtolower((string) $file->getClientOriginalExtension()) !== 'pdf') {
            throw ValidationException::withMessages(['document' => 'Отправьте документ в формате PDF.']);
        }
    }
}
