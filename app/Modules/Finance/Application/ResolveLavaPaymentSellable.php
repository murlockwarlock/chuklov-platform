<?php

namespace App\Modules\Finance\Application;

use App\Modules\Commerce\Domain\Models\PurchaseItem;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Tracker\Domain\Models\TrackerPlanVersion;

final class ResolveLavaPaymentSellable
{
    public function handle(FinancialObligation $obligation): ?array
    {
        $service = $obligation->booking?->service;
        if ($service instanceof Service && $service->catalogItemType() === CatalogItemType::Service) {
            return [
                'type' => Service::class,
                'id' => (int) $service->getKey(),
            ];
        }

        $item = $obligation->purchase?->items->first();
        if (! $item instanceof PurchaseItem
            || ! is_array($item->product_snapshot)
            || ! in_array($item->product_snapshot['kind'] ?? null, ['online_product', 'tracker_plan'], true)
            || ! in_array($item->sellable_type, [Service::class, TrackerPlanVersion::class], true)
            || (int) $item->sellable_id <= 0) {
            return null;
        }

        return [
            'type' => $item->sellable_type,
            'id' => (int) $item->sellable_id,
        ];
    }
}
