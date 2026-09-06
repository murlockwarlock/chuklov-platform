<?php

namespace App\Modules\Scenarios\Infrastructure\Storage;

use App\Modules\Scenarios\Domain\Contracts\NotificationTemplateMediaStorageInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FilesystemNotificationTemplateMediaStorage implements NotificationTemplateMediaStorageInterface
{
    /** @var array<string, string> */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/json' => 'json',
        'application/xml' => 'xml',
        'text/xml' => 'xml',
        'application/zip' => 'zip',
        'application/x-7z-compressed' => '7z',
        'application/x-rar-compressed' => 'rar',
        'application/vnd.rar' => 'rar',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
        'application/vnd.oasis.opendocument.presentation' => 'odp',
    ];

    public function store(int $organizationId, UploadedFile $file): string
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['media_image' => 'Файл не удалось загрузить.']);
        }

        $realPath = $file->getRealPath();
        $size = $file->getSize();
        if ($realPath === false || ! is_file($realPath) || $size === false || $size <= 0) {
            throw ValidationException::withMessages(['media_image' => 'Файл не удалось прочитать.']);
        }

        $mimeType = $this->mimeType($realPath);
        $extension = self::MIME_EXTENSIONS[$mimeType] ?? null;
        if ($extension === null) {
            throw ValidationException::withMessages(['media_image' => 'Формат файла не поддерживается Telegram.']);
        }
        if ($size > $this->maxBytes()) {
            throw ValidationException::withMessages(['media_image' => 'Размер файла не должен превышать 50 МБ.']);
        }
        if (str_starts_with($mimeType, 'image/') && $size > $this->photoMaxBytes()) {
            throw ValidationException::withMessages(['media_image' => 'Фото должно быть размером до 10 МБ.']);
        }

        $path = "notification-template/{$organizationId}/".Str::uuid()->toString().".{$extension}";
        $stored = Storage::disk($this->disk())->putFileAs(dirname($path), $file, basename($path));
        if (! is_string($stored)) {
            throw ValidationException::withMessages(['media_image' => 'Не удалось сохранить файл.']);
        }

        return $stored;
    }

    public function isManagedPath(int $organizationId, ?string $path): bool
    {
        if (! is_string($path)) {
            return false;
        }

        return preg_match(
            '/\Anotification-template\/(\d+)\/[0-9a-f-]{36}\.(jpg|png|webp|mp4|pdf|txt|csv|json|xml|zip|7z|rar|doc|docx|xls|xlsx|ppt|pptx|odt|ods|odp)\z/i',
            $path,
            $matches,
        ) === 1 && (int) $matches[1] === $organizationId;
    }

    public function readStream(int $organizationId, string $path): mixed
    {
        if (! $this->isManagedPath($organizationId, $path)) {
            return null;
        }

        $stream = Storage::disk($this->disk())->readStream($path);

        return is_resource($stream) ? $stream : null;
    }

    public function delete(int $organizationId, string $path): void
    {
        if ($this->isManagedPath($organizationId, $path)) {
            Storage::disk($this->disk())->delete($path);
        }
    }

    private function disk(): string
    {
        return (string) config('broadcast_media.disk', 'private');
    }

    private function maxBytes(): int
    {
        return max(1, (int) config('broadcast_media.max_bytes', 52_428_800));
    }

    private function photoMaxBytes(): int
    {
        return max(1, (int) config('broadcast_media.photo_max_bytes', 10_485_760));
    }

    private function mimeType(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($mime) ? strtolower($mime) : 'application/octet-stream';
    }
}
