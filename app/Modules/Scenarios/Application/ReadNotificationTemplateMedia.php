<?php

namespace App\Modules\Scenarios\Application;

use App\Models\User;
use App\Modules\Scenarios\Domain\Contracts\NotificationTemplateMediaStorageInterface;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class ReadNotificationTemplateMedia
{
    public function __construct(private ScenarioAuthorization $authorization, private NotificationTemplateMedia $media, private NotificationTemplateMediaStorageInterface $storage) {}

    /** @return array{path: string, name: string, type: string} */
    public function handle(User $actor, int $versionId, int $mediaIndex): array
    {
        $organization = $this->authorization->authorizeView($actor);
        $version = NotificationTemplateVersion::query()->where('organization_id', $organization->getKey())->whereKey($versionId)->firstOrFail();
        $item = $version->media === null ? null : ($this->media->items($version->media)[$mediaIndex] ?? null);
        if ($item === null || ! $this->storage->isManagedPath((int) $organization->getKey(), $item['source'])) {
            throw new ModelNotFoundException;
        }
        $stream = $this->storage->readStream((int) $organization->getKey(), $item['source']);
        if (! is_resource($stream)) {
            throw new ModelNotFoundException;
        }
        $metadata = stream_get_meta_data($stream);
        fclose($stream);
        $path = $metadata['uri'] ?? null;
        if (! is_string($path) || ! is_file($path)) {
            throw new ModelNotFoundException;
        }
        $name = preg_replace('/[\x00-\x1f\x7f"\/\\\\]+/', '_', $item['name'] ?? 'media') ?: 'media';

        return ['path' => $path, 'name' => $name, 'type' => $item['type']];
    }
}
