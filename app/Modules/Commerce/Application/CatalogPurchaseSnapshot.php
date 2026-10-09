<?php

namespace App\Modules\Commerce\Application;

use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Services\Domain\Models\Service;
use Carbon\CarbonImmutable;

final class CatalogPurchaseSnapshot
{
    /** @return array<string, mixed> */
    public function forService(Service $product, Money $amount, string $kind): array
    {
        return [
            'kind' => $kind,
            'service_id' => (int) $product->getKey(),
            'name' => (string) $product->name,
            'summary' => $product->summary,
            'description_ru' => $product->description_ru,
            'description_en' => $product->description_en,
            'catalog_type' => $product->catalogItemType()->value,
            'price_minor' => $amount->minorUnits(),
            'currency' => $amount->currency()->value,
            'captured_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];
    }
}
