<?php

namespace App\Modules\Content\Application;

use App\Modules\Content\Domain\Contracts\ContentMediaStorageInterface;
use App\Modules\Content\Domain\Models\ContentSection;

final readonly class ContentVideoUrlResolver
{
    public function __construct(private ContentMediaStorageInterface $storage) {}

    public function resolve(ContentSection $section): ?string
    {
        $path = $section->media['video'] ?? null;

        return is_string($path) && str_ends_with($path, '.mp4') && $this->storage->isManagedPath((int) $section->organization_id, $path)
            ? $this->storage->url($path) : null;
    }
}
