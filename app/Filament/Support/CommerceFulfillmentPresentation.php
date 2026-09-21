<?php

namespace App\Filament\Support;

use App\Modules\Commerce\Domain\Enums\CommerceFulfillmentStatus;
use App\Modules\Commerce\Domain\Enums\PurchaseStatus;
use App\Modules\Commerce\Domain\Models\PurchaseFulfillment;
use App\Modules\Commerce\Domain\Models\PurchaseItem;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Services\Domain\Models\Service;

final class CommerceFulfillmentPresentation
{
    public static function productName(FinancialObligation $record): string
    {
        $service = $record->booking?->service ?? $record->service;

        if ($service instanceof Service) {
            return $service->name;
        }

        $names = $record->purchase?->items
            ->map(function (PurchaseItem $item): ?string {
                $snapshot = $item->product_snapshot;

                foreach (['name', 'plan_name'] as $key) {
                    if (is_array($snapshot) && is_string($snapshot[$key] ?? null) && filled($snapshot[$key])) {
                        return $snapshot[$key];
                    }
                }

                return null;
            })
            ->filter()
            ->values()
            ->all() ?? [];

        return $names === [] ? ($record->purchase === null ? '—' : 'Покупка') : implode(', ', $names);
    }

    public static function status(FinancialObligation $record): string
    {
        $purchase = $record->purchase;
        $items = $purchase?->items;

        if ($purchase === null || $items === null || $items->isEmpty()) {
            return '—';
        }

        $fulfillments = $items->map(fn (PurchaseItem $item): ?PurchaseFulfillment => $item->fulfillment)
            ->filter()
            ->values();

        if ($fulfillments->isEmpty()) {
            return '—';
        }

        if ($fulfillments->every(fn (PurchaseFulfillment $fulfillment): bool => $fulfillment->status === CommerceFulfillmentStatus::Fulfilled)) {
            return self::isPhysical($record) ? 'Товар передан' : 'Доступ выдан';
        }

        if ($purchase->status !== PurchaseStatus::Paid) {
            return 'Ожидает оплаты';
        }

        if ($fulfillments->contains(fn (PurchaseFulfillment $fulfillment): bool => $fulfillment->status === CommerceFulfillmentStatus::Failed)) {
            return 'Ошибка выдачи';
        }

        if ($fulfillments->contains(fn (PurchaseFulfillment $fulfillment): bool => $fulfillment->status === CommerceFulfillmentStatus::Processing)) {
            return 'Выдаётся';
        }

        if ($fulfillments->contains(fn (PurchaseFulfillment $fulfillment): bool => $fulfillment->provider_type === 'manual')) {
            return self::isPhysical($record) ? 'Ожидает передачи' : 'Требуется выдача';
        }

        return 'Выдаётся автоматически';
    }

    public static function manualFulfillment(FinancialObligation $record): ?PurchaseFulfillment
    {
        $fulfillments = $record->purchase?->items
            ->map(fn (PurchaseItem $item): ?PurchaseFulfillment => $item->fulfillment)
            ->filter(fn (?PurchaseFulfillment $fulfillment): bool => $fulfillment instanceof PurchaseFulfillment
                && $fulfillment->provider_type === 'manual'
                && $fulfillment->status !== CommerceFulfillmentStatus::Fulfilled)
            ->values();

        return $fulfillments?->count() === 1 ? $fulfillments->first() : null;
    }

    public static function isPhysical(FinancialObligation $record): bool
    {
        $snapshot = $record->purchase?->items->first()?->product_snapshot;

        return is_array($snapshot) && ($snapshot['catalog_type'] ?? null) === 'physical_product';
    }
}
