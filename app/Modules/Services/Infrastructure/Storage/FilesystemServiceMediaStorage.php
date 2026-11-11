<?php

namespace App\Modules\Services\Infrastructure\Storage;

use App\Modules\Services\Domain\Contracts\ServiceMediaStorageInterface;
use App\Support\Images\ImageNormalizationException;
use App\Support\Images\NormalizeUploadedImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class FilesystemServiceMediaStorage implements ServiceMediaStorageInterface
{
    public function __construct(private readonly NormalizeUploadedImage $images) {}

    public function store(int $organizationId, UploadedFile $file): string
    {
        try {
            $normalized = $this->images->normalize($file, $this->maxBytes());
        } catch (ImageNormalizationException $exception) {
            throw ValidationException::withMessages([
                'service_image' => [$this->validationMessage($exception)],
            ]);
        }

        $path = "services/{$organizationId}/".Str::uuid()->toString().".{$normalized->extension}";
        if (! Storage::disk($this->disk())->put($path, $normalized->contents)) {
            throw ValidationException::withMessages([
                'service_image' => ['Не удалось сохранить изображение.'],
            ]);
        }

        return $path;
    }

    public function isManagedPath(int $organizationId, ?string $path): bool
    {
        if ($path === null) {
            return false;
        }

        $matched = preg_match(
            '/\Aservices\/(\d+)\/([0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})\.(jpg|png|webp)\z/i',
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

        $disk = Storage::disk($this->disk());

        if (! $disk->exists($path)) {
            return;
        }

        if ($disk->delete($path) === false && ! $disk->missing($path)) {
            throw new RuntimeException(
                "Managed service media deletion failed for organization {$organizationId} at {$path}.",
            );
        }
    }

    private function disk(): string
    {
        return (string) config('service_media.disk', 'public');
    }

    private function maxBytes(): int
    {
        return max(1, (int) config('service_media.max_bytes', 15_728_640));
    }

    private function validationMessage(ImageNormalizationException $exception): string
    {
        if ($exception->reason === ImageNormalizationException::TOO_LARGE) {
            return 'Изображение должно быть размером до 15 МБ.';
        }

        return 'Не удалось прочитать изображение. Попробуйте другой файл.';
    }
}
