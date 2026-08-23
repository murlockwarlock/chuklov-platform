<?php

namespace App\Modules\ClientCompanion\Application\Actions;

use App\Modules\Attachments\Domain\Contracts\AttachmentScannerInterface;
use App\Modules\Attachments\Domain\Contracts\AttachmentStorageInterface;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class UploadCompanionImages
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly AttachmentStorageInterface $storage,
        private readonly AttachmentScannerInterface $scanner,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     * @return list<MedicalAttachment>
     */
    public function handle(Client $client, array $files): array
    {
        $organization = $this->context->organization();
        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw ValidationException::withMessages(['images' => 'Изображение недоступно для этого аккаунта.']);
        }
        $maxImages = max(1, (int) config('ai.companion.maximum_images_per_turn', 10));
        if ($files === [] || count($files) > $maxImages) {
            throw ValidationException::withMessages(['images' => 'Отправьте меньше изображений.']);
        }

        $stored = [];
        $created = [];
        try {
            foreach ($files as $file) {
                $this->assertImage($file);
                $scan = $this->scanner->scan($file);
                $uuid = (string) Str::uuid();
                $storedAttachment = $this->storage->store((int) $organization->getKey(), $file, $uuid);
                $stored[] = $storedAttachment;
                if (! $scan->status->isAvailable()) {
                    throw ValidationException::withMessages(['images' => 'Изображение не прошло проверку безопасности.']);
                }

                $created[] = [
                    'uuid' => $uuid,
                    'organization_id' => $organization->getKey(),
                    'client_id' => $client->getKey(),
                    'uploaded_by_user_id' => null,
                    'attachment_type' => AttachmentType::CompanionImage,
                    'disk' => $storedAttachment->disk,
                    'storage_path' => $storedAttachment->storagePath,
                    'original_filename' => $storedAttachment->originalFilename,
                    'mime_type' => $storedAttachment->mimeType,
                    'size_bytes' => $storedAttachment->sizeBytes,
                    'sha256_checksum' => $storedAttachment->sha256Checksum,
                    'scan_status' => $scan->status,
                    'scan_result_metadata' => $scan->metadata,
                    'scanned_at' => now(),
                ];
            }

            $totalBytes = array_sum(array_map(static fn (array $item): int => (int) $item['size_bytes'], $created));
            if ($totalBytes > (int) config('ai.companion.maximum_image_total_bytes', 20_971_520)) {
                throw ValidationException::withMessages(['images' => 'Изображения слишком большие. Отправьте меньше или меньшего размера.']);
            }

            return DB::transaction(function () use ($organization, $created): array {
                $result = [];
                foreach ($created as $attributes) {
                    $attachment = new MedicalAttachment;
                    $attachment->forceFill($attributes);
                    $attachment->save();
                    $this->audit->handle(
                        organization: $organization,
                        actor: null,
                        action: 'attachment.uploaded',
                        targetType: MedicalAttachment::class,
                        targetId: (string) $attachment->getKey(),
                        metadata: [
                            'source' => 'client_companion',
                            'attachment_type' => AttachmentType::CompanionImage->value,
                            'mime_type' => $attachment->mime_type,
                            'size_bytes' => $attachment->size_bytes,
                            'scan_status' => $attachment->scan_status->value,
                        ],
                    );
                    $result[] = $attachment->refresh();
                }

                return $result;
            });
        } catch (\Throwable $exception) {
            foreach ($stored as $storedAttachment) {
                $this->storage->delete($storedAttachment->storagePath);
            }

            throw $exception;
        }
    }

    private function assertImage(UploadedFile $file): void
    {
        if (! $file->isValid() || $file->getSize() === false || $file->getSize() <= 0
            || $file->getSize() > (int) config('ai.companion.maximum_image_bytes', 10_485_760)) {
            throw ValidationException::withMessages(['images' => 'Изображение слишком большое или повреждено.']);
        }

        $path = $file->getRealPath();
        $info = $path === false ? false : @getimagesize($path);
        if ($info === false) {
            throw ValidationException::withMessages(['images' => 'Файл не является изображением.']);
        }
        $mime = strtolower($info['mime']);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages(['images' => 'Поддерживаются только изображения JPG, PNG или WebP.']);
        }
        $pixels = $info[0] * $info[1];
        if ($pixels < 1 || $pixels > (int) config('ai.companion.maximum_image_pixels', 25_000_000)) {
            throw ValidationException::withMessages(['images' => 'Размер изображения не поддерживается.']);
        }
    }
}
