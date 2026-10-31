<?php

namespace App\Modules\Attachments\Infrastructure\Storage;

use App\Modules\Attachments\Domain\Contracts\AttachmentStorageInterface;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Exceptions\InvalidAttachmentException;
use App\Modules\Attachments\Domain\Exceptions\UnsupportedDicomException;
use App\Modules\Attachments\Domain\ValueObjects\StoredAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class PrivateMedicalAttachmentStorage implements AttachmentStorageInterface
{
    public function maxBytes(?AttachmentType $attachmentType = null): int
    {
        if ($attachmentType === AttachmentType::AudioRecording) {
            return (int) config('medical.audio_attachment_max_bytes', 67_108_864);
        }

        return (int) config('medical.attachment_max_bytes', 20_971_520);
    }

    /** @var array<string, list<string>> */
    private const MEDICAL_MIME_EXTENSIONS = [
        'application/pdf' => ['pdf'],
        'text/plain' => ['txt'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    /** @var array<string, list<string>> */
    private const AUDIO_MIME_EXTENSIONS = [
        'audio/mpeg' => ['mp3'],
        'audio/mp4' => ['m4a', 'mp4'],
        'audio/x-m4a' => ['m4a'],
        'audio/wav' => ['wav'],
        'audio/x-wav' => ['wav'],
        'audio/ogg' => ['ogg'],
        'audio/webm' => ['webm'],
    ];

    public function store(int $organizationId, UploadedFile $file, string $uuid, ?AttachmentType $attachmentType = null): StoredAttachment
    {
        if (! $file->isValid()) {
            throw new InvalidAttachmentException('Некорректный или повреждённый файл.');
        }

        $realPath = $file->getRealPath();

        if ($realPath === false || ! file_exists($realPath)) {
            throw new InvalidAttachmentException('Файл не найден на сервере.');
        }

        $this->assertNotDicom($file, $realPath);

        $size = $file->getSize();

        if ($size === false || $size <= 0) {
            throw new InvalidAttachmentException('Файл пуст.');
        }

        $maxBytes = $this->maxBytes($attachmentType);

        if ($size > $maxBytes) {
            $limitMb = round($maxBytes / (1024 * 1024), 1);
            throw new InvalidAttachmentException("Размер файла превышает допустимый лимит {$limitMb} МБ.");
        }

        $sniffedMime = $this->sniffMimeType($realPath);

        $allowedMimeExtensions = $this->allowedMimeExtensions($attachmentType);

        if (! isset($allowedMimeExtensions[$sniffedMime])) {
            throw new InvalidAttachmentException($attachmentType === AttachmentType::AudioRecording
                ? "Неподдерживаемый аудиоформат ({$sniffedMime}). Допустимы MP3, M4A, WAV, OGG и WebM."
                : "Неподдерживаемый тип файла ({$sniffedMime}). Допустимы только PDF, текстовые файлы и изображения (JPG, PNG, WebP).");
        }

        $clientExtension = strtolower((string) $file->getClientOriginalExtension());
        $allowedExtensions = $allowedMimeExtensions[$sniffedMime];

        if ($clientExtension !== '' && ! in_array($clientExtension, $allowedExtensions, true)) {
            throw new InvalidAttachmentException("Расширение файла .{$clientExtension} не соответствует его реальному содержимому ({$sniffedMime}).");
        }

        $extension = $allowedExtensions[0];
        $storagePath = "medical/attachments/{$organizationId}/{$uuid}.{$extension}";

        $stored = Storage::disk('private')->putFileAs(
            dirname($storagePath),
            $file,
            basename($storagePath),
        );

        if ($stored === false) {
            throw new InvalidAttachmentException('Не удалось сохранить файл во внутреннее хранилище.');
        }

        $checksum = hash_file('sha256', $realPath);

        if ($checksum === false) {
            $checksum = '';
        }

        $originalName = Str::limit(basename((string) $file->getClientOriginalName()), 255, '');

        return new StoredAttachment(
            disk: 'private',
            storagePath: $stored,
            originalFilename: $originalName,
            mimeType: $sniffedMime,
            sizeBytes: $size,
            sha256Checksum: $checksum,
        );
    }

    /** @return resource */
    public function readStream(string $storagePath)
    {
        $stream = Storage::disk('private')->readStream($storagePath);

        if (! is_resource($stream)) {
            throw new RuntimeException("Файл {$storagePath} недоступен в хранилище.");
        }

        return $stream;
    }

    public function delete(string $storagePath): void
    {
        Storage::disk('private')->delete($storagePath);
    }

    /** @return array<string, list<string>> */
    private function allowedMimeExtensions(?AttachmentType $attachmentType): array
    {
        return $attachmentType === AttachmentType::AudioRecording
            ? self::AUDIO_MIME_EXTENSIONS
            : self::MEDICAL_MIME_EXTENSIONS;
    }

    private function assertNotDicom(UploadedFile $file, string $realPath): void
    {
        $clientExtension = strtolower((string) $file->getClientOriginalExtension());

        if (in_array($clientExtension, ['dcm', 'dicom'], true)) {
            throw new UnsupportedDicomException('Файлы формата DICOM не поддерживаются. Пожалуйста, загрузите заключение или снимок в формате PDF, текст или изображение.');
        }

        $clientMime = strtolower((string) $file->getClientMimeType());

        if (in_array($clientMime, ['application/dicom', 'application/x-dicom', 'image/x-dicom'], true)) {
            throw new UnsupportedDicomException('Файлы формата DICOM не поддерживаются. Пожалуйста, загрузите заключение или снимок в формате PDF, текст или изображение.');
        }

        $fileSize = filesize($realPath);

        if ($fileSize !== false && $fileSize >= 132) {
            $handle = fopen($realPath, 'rb');

            if ($handle !== false) {
                fseek($handle, 128);
                $magic = fread($handle, 4);
                fclose($handle);

                if ($magic === 'DICM') {
                    throw new UnsupportedDicomException('Файлы формата DICOM не поддерживаются. Пожалуйста, загрузите заключение или снимок в формате PDF, текст или изображение.');
                }
            }
        }
    }

    private function sniffMimeType(string $realPath): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return 'application/octet-stream';
        }

        $mime = finfo_file($finfo, $realPath);
        finfo_close($finfo);

        return is_string($mime) ? strtolower($mime) : 'application/octet-stream';
    }
}
