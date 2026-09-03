<?php

namespace App\Modules\Content\Application;

use App\Modules\Content\Domain\Contracts\ContentMediaStorageInterface;
use App\Modules\Content\Domain\Models\ContentSection;

final class ContentImageUrlResolver
{
    public function __construct(private readonly ContentMediaStorageInterface $media) {}

    public function resolve(ContentSection $section): ?string
    {
        $image = $this->image($section);

        if ($image === null) {
            return null;
        }

        return $this->isManaged($section)
            ? $this->media->url($image)
            : $image;
    }

    public function isManaged(ContentSection $section): bool
    {
        return $this->media->isManagedPath((int) $section->organization_id, $this->image($section));
    }

    public function resolveStream(ContentSection $section): mixed
    {
        $image = $this->image($section);
        if ($image === null || ! $this->isManaged($section)) {
            return null;
        }

        $stream = $this->media->readStream((int) $section->organization_id, $image);

        return is_resource($stream) ? $stream : null;
    }

    private function image(ContentSection $section): ?string
    {
        $media = $section->media;
        $image = is_array($media) ? $media['image'] ?? null : null;

        if (! is_string($image) || trim($image) === '') {
            return null;
        }

        return trim($image);
    }
}
