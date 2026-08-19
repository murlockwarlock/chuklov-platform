<?php

namespace App\Modules\Services\Application;

use App\Modules\Services\Domain\Contracts\ServiceMediaStorageInterface;
use App\Modules\Services\Domain\Models\Service;

final class ServiceImageUrlResolver
{
    public function __construct(private readonly ServiceMediaStorageInterface $media) {}

    public function resolve(Service $service): ?string
    {
        $externalUrl = $this->stringValue($service->getAttribute('external_image_url'));

        if ($externalUrl !== null) {
            return $this->isHttpsUrl($externalUrl) ? $externalUrl : null;
        }

        $path = $this->stringValue($service->getAttribute('image_path'));

        if ($path === null) {
            return null;
        }

        if (str_starts_with($path, 'services/')) {
            return $this->media->isManagedPath((int) $service->getAttribute('organization_id'), $path)
                ? $this->media->url($path)
                : null;
        }

        return asset($path);
    }

    private function isHttpsUrl(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($value);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== '';
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
