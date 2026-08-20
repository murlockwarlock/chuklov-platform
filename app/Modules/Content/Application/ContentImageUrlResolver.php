<?php

namespace App\Modules\Content\Application;

use App\Modules\Content\Domain\Contracts\ContentMediaStorageInterface;
use App\Modules\Content\Domain\Models\ContentSection;

final class ContentImageUrlResolver
{
    public function __construct(private readonly ContentMediaStorageInterface $media) {}

    public function resolve(ContentSection $section): ?string
    {
        $media = $section->media;
        $image = is_array($media) ? $media['image'] ?? null : null;

        if (! is_string($image) || trim($image) === '') {
            return null;
        }

        $image = trim($image);

        return $this->media->isManagedPath((int) $section->organization_id, $image)
            ? $this->media->url($image)
            : $image;
    }
}
