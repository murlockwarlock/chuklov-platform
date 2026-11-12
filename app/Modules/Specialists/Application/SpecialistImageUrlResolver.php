<?php

namespace App\Modules\Specialists\Application;

use App\Modules\Specialists\Domain\Contracts\SpecialistMediaStorageInterface;
use App\Modules\Specialists\Domain\Models\Specialist;

final class SpecialistImageUrlResolver
{
    public function __construct(private readonly SpecialistMediaStorageInterface $media) {}

    public function resolve(Specialist $specialist): ?string
    {
        $path = $specialist->getAttribute('profile_image_path');
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        return $this->media->isManagedPath((int) $specialist->getAttribute('organization_id'), $path)
            ? $this->media->url($path)
            : null;
    }
}
