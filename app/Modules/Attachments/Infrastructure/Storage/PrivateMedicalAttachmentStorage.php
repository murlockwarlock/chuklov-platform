<?php

namespace App\Modules\Attachments\Infrastructure\Storage;

use App\Modules\Attachments\Domain\Contracts\AttachmentStorageInterface;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Exceptions\InvalidAttachmentException;
use App\Modules\Attachments\Domain\Exceptions\UnsupportedDicomException;
use App\Modules\Attachments\Domain\ValueObjects\StoredAttachment;
use App\Support\Images\ImageNormalizationException;
use App\Support\Images\NormalizedUploadedImage;
use App\Support\Images\NormalizeUploadedImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class PrivateMedicalAttachmentStorage implements AttachmentStorageInterface
{
    public function __construct(private readonly NormalizeUploadedImage $images) {}

    public function maxBytes(?AttachmentType $attachmentType = null): int
    {
        if ($attachmentType === AttachmentType::AudioRecording) {
            return (int) config('medical.audio_attachment_max_bytes', 67_108_864);
        }

        if ($attachmentType === AttachmentType::CompanionImage) {
            return (int) config('ai.companion.maximum_image_bytes', 10_485_760);
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
        $normalized = null;
        $allowedExtensions = [];
        if ($this->isImageCandidate($file, $sniffedMime, $attachmentType)) {
            try {
                $normalized = $this->images->normalize($file, $maxBytes, $this->maxPixels($attachmentType));
            } catch (ImageNormalizationException $exception) {
                throw new InvalidAttachmentException($this->imageValidationMessage($exception, $maxBytes), 0, $exception);
            }
        } else {
            $allowedMimeExtensions = $this->allowedMimeExtensions($attachmentType);

            if (! isset($allowedMimeExtensions[$sniffedMime])) {
                throw new InvalidAttachmentException($attachmentType === AttachmentType::AudioRecording
                    ? "Неподдерживаемый аудиоформат ({$sniffedMime}). Допустимы MP3, M4A, WAV, OGG и WebM."
                    : 'Не удалось прочитать файл. Выберите PDF, текстовый файл или фотографию.');
            }

            $clientExtension = strtolower((string) $file->getClientOriginalExtension());
            $allowedExtensions = $allowedMimeExtensions[$sniffedMime];

            if ($clientExtension !== '' && ! in_array($clientExtension, $allowedExtensions, true)) {
                throw new InvalidAttachmentException('Расширение файла не соответствует его содержимому.');
            }
        }

        $extension = $normalized?->extension ?? $allowedExtensions[0];
        $storagePath = "medical/attachments/{$organizationId}/{$uuid}.{$extension}";

        $stored = $normalized instanceof NormalizedUploadedImage
            ? (Storage::disk('private')->put($storagePath, $normalized->contents) ? $storagePath : false)
            : Storage::disk('private')->putFileAs(dirname($storagePath), $file, basename($storagePath));

        if ($stored === false) {
            throw new InvalidAttachmentException('Не удалось сохранить файл во внутреннее хранилище.');
        }

        $checksum = $normalized instanceof NormalizedUploadedImage
            ? hash('sha256', $normalized->contents)
            : hash_file('sha256', $realPath);

        if ($checksum === false) {
            $checksum = '';
        }

        $originalName = Str::limit(basename((string) $file->getClientOriginalName()), 255, '');

        return new StoredAttachment(
            disk: 'private',
            storagePath: $stored,
            originalFilename: $originalName,
            mimeType: $normalized?->mimeType ?? $sniffedMime,
            sizeBytes: $normalized?->sizeBytes ?? $size,
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

    private function isImageCandidate(UploadedFile $file, string $mimeType, ?AttachmentType $attachmentType): bool
    {
        if ($attachmentType === AttachmentType::AudioRecording) {
            return false;
        }

        return str_starts_with($mimeType, 'image/')
            || in_array(strtolower((string) $file->getClientOriginalExtension()), ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'], true);
    }

    private function maxPixels(?AttachmentType $attachmentType): ?int
    {
        return $attachmentType === AttachmentType::CompanionImage
            ? max(1, (int) config('ai.companion.maximum_image_pixels', 25_000_000))
            : null;
    }

    private function imageValidationMessage(ImageNormalizationException $exception, int $maxBytes): string
    {
        if ($exception->reason === ImageNormalizationException::TOO_LARGE) {
            return 'Размер изображения превышает допустимый лимит '.round($maxBytes / 1_048_576, 1).' МБ.';
        }

        if ($exception->reason === ImageNormalizationException::TOO_LARGE_DIMENSIONS) {
            return 'Размер изображения не поддерживается.';
        }

        return 'Не удалось прочитать изображение. Попробуйте другой файл.';
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
        unset($finfo);

        return is_string($mime) ? strtolower($mime) : 'application/octet-stream';
    }
}
