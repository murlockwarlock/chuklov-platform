<?php

namespace App\Modules\Services\Infrastructure\Storage;

use App\Modules\Services\Domain\Contracts\ServiceMediaStorageInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FilesystemServiceMediaStorage implements ServiceMediaStorageInterface
{
    /** @var array<string, string> */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public function store(int $organizationId, UploadedFile $file): string
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'service_image' => ['Изображение не удалось загрузить.'],
            ]);
        }

        $realPath = $file->getRealPath();
        $size = $file->getSize();

        if ($realPath === false || ! is_file($realPath) || $size === false || $size <= 0) {
            throw ValidationException::withMessages([
                'service_image' => ['Изображение не удалось прочитать.'],
            ]);
        }

        if ($size > $this->maxBytes()) {
            throw ValidationException::withMessages([
                'service_image' => ['Изображение должно быть JPG или PNG размером до 5 МБ.'],
            ]);
        }

        $mime = $this->sniffMimeType($realPath);
        $extension = self::MIME_EXTENSIONS[$mime] ?? null;

        if ($extension === null) {
            throw ValidationException::withMessages([
                'service_image' => ['Поддерживаются только изображения JPG и PNG.'],
            ]);
        }

        $path = "services/{$organizationId}/".Str::uuid()->toString().".{$extension}";
        $storedPath = Storage::disk($this->disk())->putFileAs(
            dirname($path),
            $file,
            basename($path),
        );

        if (! is_string($storedPath)) {
            throw ValidationException::withMessages([
                'service_image' => ['Не удалось сохранить изображение.'],
            ]);
        }

        return $storedPath;
    }

    public function isManagedPath(int $organizationId, ?string $path): bool
    {
        if ($path === null) {
            return false;
        }

        $matched = preg_match(
            '/\Aservices\/(\d+)\/([0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})\.(jpg|png)\z/i',
            $path,
            $matches,
        ) === 1;

        return $matched && (int) $matches[1] === $organizationId;
    }

    public function url(string $path): string
    {
        return Storage::disk($this->disk())->url($path);
    }

    public function deleteManaged(int $organizationId, string $path): void
    {
        if (! $this->isManagedPath($organizationId, $path)) {
            return;
        }

        Storage::disk($this->disk())->delete($path);
    }

    private function disk(): string
    {
        return (string) config('service_media.disk', 'public');
    }

    private function maxBytes(): int
    {
        return max(1, (int) config('service_media.max_bytes', 5_242_880));
    }

    private function sniffMimeType(string $path): string
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
