<?php

namespace App\Modules\Broadcasts\Application;

use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Content\Domain\Contracts\ContentMediaStorageInterface;
use App\Modules\Content\Domain\ValueObjects\ContentExternalImageUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class BroadcastCampaignMedia
{
    public function __construct(private ContentMediaStorageInterface $media) {}

    /**
     * @param  array<string, mixed>|null  $mediaInput
     * @return array{image: string, alt: string|null}|null
     */
    public function resolve(int $organizationId, ?array $mediaInput, ?string &$storedPath = null): ?array
    {
        if ($mediaInput === null || ($mediaInput['remove'] ?? false) === true) {
            return null;
        }

        $upload = $mediaInput['upload'] ?? null;
        $image = $mediaInput['image'] ?? null;
        $alt = $mediaInput['alt'] ?? null;

        if ($upload instanceof UploadedFile) {
            $storedPath = $this->media->store($organizationId, $upload);
            $image = $storedPath;
        } elseif (is_string($image)) {
            if (! $this->media->isManagedPath($organizationId, $image)) {
                try {
                    ContentExternalImageUrl::required($image);
                } catch (\InvalidArgumentException) {
                    throw ValidationException::withMessages(['media_url' => 'Укажите корректную HTTPS-ссылку на изображение.']);
                }
            }
        } else {
            throw ValidationException::withMessages(['media_image' => 'Добавьте изображение для выбранного режима.']);
        }

        if (trim($image) === '') {
            throw ValidationException::withMessages(['media_image' => 'Добавьте изображение для выбранного режима.']);
        }

        return ['image' => $image, 'alt' => is_string($alt) && trim($alt) !== '' ? trim($alt) : null];
    }

    /** @param array<string, mixed>|null $media */
    public function ensureAvailable(NotificationMessageMode $mode, ?array $media): void
    {
        if (! $mode->includesImage()) {
            return;
        }

        $image = is_string($media['image'] ?? null) ? trim($media['image']) : '';
        if ($image === '') {
            throw ValidationException::withMessages(['media_image' => 'Добавьте изображение или выберите текстовый режим.']);
        }
    }

    public function discard(int $organizationId, string $path): void
    {
        try {
            $this->media->deleteManaged($organizationId, $path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function isManagedPath(int $organizationId, ?string $path): bool
    {
        return $this->media->isManagedPath($organizationId, $path);
    }

    public function url(string $path): string
    {
        return $this->media->url($path);
    }
}
