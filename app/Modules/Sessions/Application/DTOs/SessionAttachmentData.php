<?php

namespace App\Modules\Sessions\Application\DTOs;

final readonly class SessionAttachmentData
{
    public function __construct(
        public int $attachmentId,
        public string $filename,
        public string $type,
        public string $gallery,
        public string $galleryKey,
        public int $sizeBytes,
        public ?string $downloadUrl,
        public ?string $previewUrl,
        public string $uploadedAt,
    ) {}

    /** @return array{attachment_id: int, filename: string, type: string, gallery: string, gallery_key: string, size: string, download_url: ?string, preview_url: ?string, uploaded_at: string} */
    public function toArray(): array
    {
        return [
            'attachment_id' => $this->attachmentId,
            'filename' => $this->filename,
            'type' => $this->type,
            'gallery' => $this->gallery,
            'gallery_key' => $this->galleryKey,
            'size' => self::formatSize($this->sizeBytes),
            'download_url' => $this->downloadUrl,
            'preview_url' => $this->previewUrl,
            'uploaded_at' => $this->uploadedAt,
        ];
    }

    private static function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' Б';
        }

        if ($bytes < 1024 * 1024) {
            return (string) round($bytes / 1024).' КБ';
        }

        return number_format($bytes / 1024 / 1024, 1, ',', ' ').' МБ';
    }
}
