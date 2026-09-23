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

        return $names === [] ? ($record->purchase === null ? '—' : __('Покупка')) : implode(', ', $names);
    }

    public static function status(FinancialObligation $record): string
    {
        return match (self::statusKey($record)) {
            'fulfilled' => self::isPhysical($record) ? __('Товар передан') : __('Доступ выдан'),
            'pending_payment' => __('Ожидает оплаты'),
            'failed' => __('Ошибка выдачи'),
            'processing' => __('Выдаётся'),
            'manual_pending' => self::isPhysical($record) ? __('Ожидает передачи') : __('Требуется выдача'),
            'automatic_pending' => __('Выдаётся автоматически'),
            default => '—',
        };
    }

    public static function statusColor(FinancialObligation $record): string
    {
        return match (self::statusKey($record)) {
            'fulfilled' => 'success',
            'failed' => 'danger',
            'processing', 'automatic_pending' => 'info',
            'manual_pending' => 'warning',
            default => 'gray',
        };
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

    private static function statusKey(FinancialObligation $record): ?string
    {
        $purchase = $record->purchase;
        $items = $purchase?->items;

        if ($purchase === null || $items === null || $items->isEmpty()) {
            return null;
        }

        $fulfillments = $items->map(fn (PurchaseItem $item): ?PurchaseFulfillment => $item->fulfillment)
            ->filter()
            ->values();

        if ($fulfillments->isEmpty()) {
            return null;
        }

        if ($fulfillments->every(fn (PurchaseFulfillment $fulfillment): bool => $fulfillment->status === CommerceFulfillmentStatus::Fulfilled)) {
            return 'fulfilled';
        }

        if ($purchase->status !== PurchaseStatus::Paid) {
            return 'pending_payment';
        }

        if ($fulfillments->contains(fn (PurchaseFulfillment $fulfillment): bool => $fulfillment->status === CommerceFulfillmentStatus::Failed)) {
            return 'failed';
        }

        if ($fulfillments->contains(fn (PurchaseFulfillment $fulfillment): bool => $fulfillment->status === CommerceFulfillmentStatus::Processing)) {
            return 'processing';
        }

        return $fulfillments->contains(fn (PurchaseFulfillment $fulfillment): bool => $fulfillment->provider_type === 'manual')
            ? 'manual_pending'
            : 'automatic_pending';
    }
}
