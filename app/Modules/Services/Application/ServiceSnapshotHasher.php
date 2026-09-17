<?php

namespace App\Modules\Services\Application;

use App\Modules\Services\Domain\Models\Service;

final class ServiceSnapshotHasher
{
    public function forService(Service $service): string
    {
        return hash('sha256', json_encode([
            'id' => (int) $service->getKey(),
            'organization_id' => (int) $service->organization_id,
            'name' => $service->getRawOriginal('name'),
            'summary' => $service->getRawOriginal('summary'),
            'image_path' => $service->getRawOriginal('image_path'),
            'external_image_url' => $service->getRawOriginal('external_image_url'),
            'is_active' => (bool) $service->getRawOriginal('is_active'),
            'catalog_type' => $service->getRawOriginal('catalog_type'),
            'name_ru' => $service->getRawOriginal('name_ru'),
            'name_en' => $service->getRawOriginal('name_en'),
            'description_ru' => $service->getRawOriginal('description_ru'),
            'description_en' => $service->getRawOriginal('description_en'),
            'category' => $service->getRawOriginal('category'),
            'duration_minutes' => $service->getRawOriginal('duration_minutes'),
            'buffer_minutes' => $service->getRawOriginal('buffer_minutes'),
            'formats' => $service->getRawOriginal('formats'),
            'price_minor' => $service->getRawOriginal('price_minor'),
            'price_currency' => $service->getRawOriginal('price_currency'),
            'payment_policy' => $service->getRawOriginal('payment_policy'),
            'payment_requirement' => $service->getRawOriginal('payment_requirement'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
