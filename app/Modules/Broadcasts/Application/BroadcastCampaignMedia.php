<?php

namespace App\Modules\Broadcasts\Application;

use App\Modules\Broadcasts\Domain\Contracts\BroadcastMediaStorageInterface;
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Content\Domain\Contracts\ContentMediaStorageInterface;
use App\Modules\Content\Domain\ValueObjects\ContentExternalImageUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class BroadcastCampaignMedia
{
    public function __construct(
        private BroadcastMediaStorageInterface $media,
        private ContentMediaStorageInterface $legacyMedia,
    ) {}

    /**
     * @param  array<string, mixed>|null  $mediaInput
     * @param  array<int, string>  $storedPaths
     * @return array<string, mixed>|null
     */
    public function resolve(int $organizationId, ?array $mediaInput, array &$storedPaths): ?array
    {
        if ($mediaInput === null || ($mediaInput['remove'] ?? false) === true) {
            return null;
        }

        $uploads = $mediaInput['uploads'] ?? [];
        if ($uploads === [] && ($mediaInput['upload'] ?? null) instanceof UploadedFile) {
            $uploads = [$mediaInput['upload']];
        }
        if (! is_array($uploads)) {
            throw ValidationException::withMessages(['media_image' => 'Файлы медиа имеют неверный формат.']);
        }

        if (count($uploads) > $this->maxItems()) {
            throw ValidationException::withMessages(['media_image' => 'Можно добавить не более 10 файлов за одну отправку.']);
        }

        $alt = $this->alt($mediaInput['alt'] ?? null);
        $items = [];

        foreach ($uploads as $upload) {
            if (! $upload instanceof UploadedFile) {
                throw ValidationException::withMessages(['media_image' => 'Загрузите корректные файлы медиа.']);
            }

            try {
                $path = $this->media->store($organizationId, $upload);
            } catch (ValidationException $exception) {
                $message = collect($exception->errors())->flatten()->first();

                throw ValidationException::withMessages([
                    'media_image' => is_string($message) ? $message : 'Файл медиа не удалось сохранить.',
                ]);
            }

            $storedPaths[] = $path;
            $items[] = [
                'type' => $this->typeForPath($path),
                'source' => $path,
                'alt' => $alt,
                'name' => $this->fileName($upload),
            ];
        }

        if ($items !== []) {
            $this->validateItemTypes($items);

            return $this->serialize($items);
        }

        $url = is_string($mediaInput['url'] ?? null) ? trim($mediaInput['url']) : '';
        if ($url !== '') {
            $this->validateExternalUrl($url);

            return $this->serialize([[
                'type' => $this->typeForPath($url),
                'source' => $url,
                'alt' => $alt,
                'name' => $this->fileNameFromUrl($url),
            ]]);
        }

        $existing = $mediaInput['existing'] ?? null;
        if (is_array($existing)) {
            $existingItems = $this->items($existing);
            if ($existingItems === []) {
                throw ValidationException::withMessages(['media_image' => 'Данные медиа заполнены некорректно.']);
            }
            $this->validateItemTypes($existingItems);
            $this->validateExistingItems($organizationId, $existingItems);

            if (($mediaInput['alt_provided'] ?? false) === true) {
                foreach ($existingItems as &$item) {
                    $item['alt'] = $alt;
                }
                unset($item);

                return $this->serialize($existingItems);
            }

            return $existing;
        }

        return null;
    }

    /** @param list<array{type: string, source: string, alt: string|null, name: string|null}> $items */
    private function validateExistingItems(int $organizationId, array $items): void
    {
        foreach ($items as $item) {
            if ($this->isManagedPath($organizationId, $item['source'])) {
                continue;
            }

            $this->validateExternalUrl($item['source']);
        }
    }

    /**
     * @param  array<string, mixed>|null  $media
     * @return list<array{type: string, source: string, alt: string|null, name: string|null}>
     */
    public function items(?array $media): array
    {
        if (! is_array($media)) {
            return [];
        }

        $rawItems = $media['items'] ?? null;
        if (is_array($rawItems) && array_is_list($rawItems)) {
            $items = [];
            foreach ($rawItems as $rawItem) {
                if (! is_array($rawItem)) {
                    continue;
                }

                $source = $rawItem['source'] ?? $rawItem['image'] ?? null;
                if (! is_string($source) || trim($source) === '') {
                    continue;
                }

                $type = $rawItem['type'] ?? $this->typeForPath($source);
                if (! is_string($type) || ! in_array($type, ['photo', 'video', 'document'], true)) {
                    continue;
                }
                $alt = is_string($rawItem['alt'] ?? null) && trim($rawItem['alt']) !== ''
                    ? trim($rawItem['alt'])
                    : null;
                $name = is_string($rawItem['name'] ?? null) && trim($rawItem['name']) !== ''
                    ? trim($rawItem['name'])
                    : null;
                $items[] = [
                    'type' => $type,
                    'source' => trim($source),
                    'alt' => $alt,
                    'name' => $name,
                ];
            }

            return $items;
        }

        $image = $media['image'] ?? null;
        if (! is_string($image) || trim($image) === '') {
            return [];
        }

        return [[
            'type' => 'photo',
            'source' => trim($image),
            'alt' => is_string($media['alt'] ?? null) && trim($media['alt']) !== '' ? trim($media['alt']) : null,
            'name' => null,
        ]];
    }

    /** @param array<string, mixed>|null $media */
    public function ensureAvailable(NotificationMessageMode $mode, ?array $media): void
    {
        if (! $mode->includesImage()) {
            return;
        }

        $items = $this->items($media);
        if ($items === []) {
            throw ValidationException::withMessages(['media_image' => 'Добавьте медиа или выберите текстовый режим.']);
        }
        if (count($items) > $this->maxItems()) {
            throw ValidationException::withMessages(['media_image' => 'Можно отправить не более 10 файлов за одну отправку.']);
        }

        $this->validateItemTypes($items);
    }

    /**
     * @param  array<string, mixed>|null  $media
     * @return list<string>
     */
    public function managedPaths(int $organizationId, ?array $media): array
    {
        $paths = [];
        foreach ($this->items($media) as $item) {
            if ($this->isManagedPath($organizationId, $item['source'])) {
                $paths[] = $item['source'];
            }
        }

        return $paths;
    }

    public function discard(int $organizationId, string $path): void
    {
        try {
            $this->deleteManaged($organizationId, $path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function isManagedPath(int $organizationId, ?string $path): bool
    {
        return $path !== null
            && ($this->media->isManagedPath($organizationId, $path) || $this->legacyMedia->isManagedPath($organizationId, $path));
    }

    public function readStream(int $organizationId, string $path): mixed
    {
        if ($this->media->isManagedPath($organizationId, $path)) {
            return $this->media->readStream($organizationId, $path);
        }

        if ($this->legacyMedia->isManagedPath($organizationId, $path)) {
            return $this->legacyMedia->readStream($organizationId, $path);
        }

        return null;
    }

    private function deleteManaged(int $organizationId, string $path): void
    {
        if ($this->media->isManagedPath($organizationId, $path)) {
            $this->media->deleteManaged($organizationId, $path);

            return;
        }

        $this->legacyMedia->deleteManaged($organizationId, $path);
    }

    /**
     * @param  list<array{type: string, source: string, alt: string|null, name: string|null}>  $items
     * @return array{image: string, alt: string|null}|array{items: list<array{type: string, source: string, alt: string|null, name: string|null}>}
     */
    private function serialize(array $items): array
    {
        if (count($items) === 1 && $items[0]['type'] === 'photo') {
            return [
                'image' => $items[0]['source'],
                'alt' => $items[0]['alt'],
            ];
        }

        return ['items' => $items];
    }

    private function typeForPath(string $path): string
    {
        $extension = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION));

        return match ($extension) {
            'mp4' => 'video',
            'jpg', 'jpeg', 'png', 'webp' => 'photo',
            default => 'document',
        };
    }

    private function fileName(UploadedFile $file): ?string
    {
        $name = trim($file->getClientOriginalName());

        return $name === '' ? null : mb_substr(preg_replace('/[^\pL\pN._ -]+/u', '', $name) ?: 'media', 0, 180);
    }

    private function fileNameFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path)) {
            return null;
        }

        $name = basename($path);

        return $name === '' || $name === '/' ? null : mb_substr($name, 0, 180);
    }

    private function alt(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || mb_strlen(trim($value)) > 255) {
            throw ValidationException::withMessages(['media_alt' => 'Описание медиа слишком длинное.']);
        }

        return trim($value) === '' ? null : trim($value);
    }

    private function validateExternalUrl(string $url): void
    {
        try {
            ContentExternalImageUrl::required($url);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['media_url' => 'Укажите корректную HTTPS-ссылку на медиа.']);
        }
    }

    private function maxItems(): int
    {
        return max(2, (int) config('broadcast_media.max_items', 10));
    }

    /** @param list<array{type: string, source: string, alt: string|null, name: string|null}> $items */
    private function validateItemTypes(array $items): void
    {
        $types = array_values(array_unique(array_column($items, 'type')));
        if (count($items) > 1 && in_array('document', $types, true) && count($types) > 1) {
            throw ValidationException::withMessages(['media_image' => 'Документы можно объединять в альбом только с документами.']);
        }
    }
}
