<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Channels\Domain\ValueObjects\NotificationMedia;
use App\Modules\Scenarios\Domain\Contracts\NotificationTemplateMediaStorageInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class NotificationTemplateMedia
{
    public function __construct(
        private NotificationTemplateMediaStorageInterface $storage,
    ) {}

    /** @param list<string> $storedPaths */
    public function prepare(
        int $organizationId,
        mixed $uploads,
        mixed $url,
        ?array $existing,
        array &$storedPaths,
    ): ?array {
        $files = $this->files($uploads);
        $externalUrl = is_string($url) ? trim($url) : '';

        if ($files !== [] && $externalUrl !== '') {
            throw ValidationException::withMessages([
                'media_image' => 'Выберите файлы или ссылку на медиа, но не оба варианта.',
                'media_url' => 'Выберите файлы или ссылку на медиа, но не оба варианта.',
            ]);
        }

        if ($files !== []) {
            if (count($files) > 10) {
                throw ValidationException::withMessages(['media_image' => 'Можно добавить не более 10 файлов.']);
            }

            $items = [];
            try {
                foreach ($files as $file) {
                    $path = $this->storage->store($organizationId, $file);
                    $storedPaths[] = $path;
                    $items[] = [
                        'type' => $this->typeFor($file->getMimeType(), $file->getClientOriginalName()),
                        'source' => $path,
                        'name' => $this->fileName($file),
                    ];
                }
            } catch (ValidationException $exception) {
                throw $this->mediaException($exception);
            }

            $this->validateGroup($items);

            return ['items' => $items];
        }

        if ($externalUrl !== '') {
            $this->validateUrl($externalUrl);

            return ['items' => [[
                'type' => $this->typeFor('', $externalUrl),
                'source' => $externalUrl,
                'name' => $this->fileNameFromUrl($externalUrl),
            ]]];
        }

        if ($existing !== null) {
            $items = $this->items($existing);
            $this->validateGroup($items);
            $this->validateExisting($organizationId, $items);

            return ['items' => $items];
        }

        return null;
    }

    /** @param array{items: list<array{type: string, source: string, name: string|null}>}|null $media
     * @return list<NotificationMedia>
     */
    public function messages(int $organizationId, ?array $media): array
    {
        $items = $media === null ? [] : $this->items($media);

        return array_map(function (array $item) use ($organizationId): NotificationMedia {
            if ($this->storage->isManagedPath($organizationId, $item['source'])) {
                $stream = $this->storage->readStream($organizationId, $item['source']);
                if (! is_resource($stream)) {
                    throw new InvalidArgumentException('The notification template media is unavailable.');
                }

                return new NotificationMedia(
                    type: $item['type'],
                    stream: $stream,
                    fileName: $item['name'],
                );
            }

            $this->validateUrl($item['source']);

            return new NotificationMedia(
                type: $item['type'],
                url: $item['source'],
                fileName: $item['name'],
            );
        }, $items);
    }

    public function discard(int $organizationId, string $path): void
    {
        $this->storage->delete($organizationId, $path);
    }

    /** @param array{items: list<array{type: string, source: string, name: string|null}>} $media
     * @return list<array{type: string, source: string, name: string|null}>
     */
    private function items(array $media): array
    {
        if (! is_array($media['items'] ?? null) || ! array_is_list($media['items']) || $media['items'] === [] || count($media['items']) > 10) {
            throw new InvalidArgumentException('The notification template media is invalid.');
        }

        $items = [];
        foreach ($media['items'] as $item) {
            if (! is_array($item)
                || ! in_array($item['type'] ?? null, ['photo', 'video', 'document'], true)
                || ! is_string($item['source'] ?? null)
                || trim($item['source']) === '') {
                throw new InvalidArgumentException('The notification template media is invalid.');
            }

            $name = $item['name'] ?? null;
            if ($name !== null && (! is_string($name) || trim($name) === '' || mb_strlen($name) > 255)) {
                throw new InvalidArgumentException('The notification template media is invalid.');
            }

            $items[] = [
                'type' => (string) $item['type'],
                'source' => trim($item['source']),
                'name' => $name === null ? null : trim($name),
            ];
        }

        return $items;
    }

    /** @param list<array{type: string, source: string, name: string|null}> $items */
    private function validateGroup(array $items): void
    {
        if ($items === [] || count($items) > 10) {
            throw ValidationException::withMessages(['media_image' => 'Добавьте от 1 до 10 файлов медиа.']);
        }

        $types = array_values(array_unique(array_column($items, 'type')));
        if (count($items) > 1 && in_array('document', $types, true) && count($types) > 1) {
            throw ValidationException::withMessages(['media_image' => 'Документы нельзя объединять в альбом с фото или видео.']);
        }
    }

    /** @param list<array{type: string, source: string, name: string|null}> $items */
    private function validateExisting(int $organizationId, array $items): void
    {
        foreach ($items as $item) {
            if (! $this->storage->isManagedPath($organizationId, $item['source'])) {
                $this->validateUrl($item['source']);
            }
        }
    }

    /** @return list<UploadedFile> */
    private function files(mixed $uploads): array
    {
        if ($uploads === null || $uploads === '') {
            return [];
        }

        $files = $uploads instanceof UploadedFile ? [$uploads] : $uploads;
        if (! is_array($files) || ! array_is_list($files)) {
            throw ValidationException::withMessages(['media_image' => 'Загрузите корректные файлы медиа.']);
        }

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages(['media_image' => 'Загрузите корректные файлы медиа.']);
            }
        }

        return $files;
    }

    private function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || ! is_string($parts['host'] ?? null) || isset($parts['user'], $parts['pass'])) {
            throw ValidationException::withMessages(['media_url' => 'Укажите корректную HTTPS-ссылку на медиа.']);
        }
    }

    private function typeFor(string $mime, string $name): string
    {
        $mime = strtolower($mime);
        $extension = strtolower(pathinfo(parse_url($name, PHP_URL_PATH) ?: $name, PATHINFO_EXTENSION));

        if (str_starts_with($mime, 'image/') || in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return 'photo';
        }
        if ($mime === 'video/mp4' || $extension === 'mp4') {
            return 'video';
        }

        return 'document';
    }

    private function fileName(UploadedFile $file): ?string
    {
        $name = trim((string) $file->getClientOriginalName());

        return $name === '' ? null : mb_substr($name, 0, 255);
    }

    private function fileNameFromUrl(string $url): ?string
    {
        $name = basename((string) parse_url($url, PHP_URL_PATH));

        return $name === '' || $name === '/' ? null : mb_substr($name, 0, 255);
    }

    private function mediaException(ValidationException $exception): ValidationException
    {
        $message = collect($exception->errors())->flatten()->first();

        return ValidationException::withMessages([
            'media_image' => is_string($message) ? $message : 'Файл медиа не удалось сохранить.',
        ]);
    }
}
