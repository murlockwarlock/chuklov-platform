<?php

namespace App\Filament\Support;

use App\Modules\Finance\Application\PaymentGatewayReconciliationReason;
use App\Modules\Finance\Domain\Enums\PaymentGatewayEventType;
use App\Modules\Finance\Domain\Models\PaymentGatewayEvent;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Services\Domain\Models\Service;

final class PaymentGatewayReconciliationPresentation
{
    public static function eventType(PaymentGatewayEvent $record): string
    {
        return match ($record->event_type) {
            PaymentGatewayEventType::Settlement => 'Успешная оплата',
            PaymentGatewayEventType::Failure => 'Неуспешная оплата',
            PaymentGatewayEventType::Refund => 'Возврат',
            PaymentGatewayEventType::Chargeback => 'Оспаривание платежа',
            PaymentGatewayEventType::Unknown => 'Неизвестное событие',
        };
    }

    public static function reason(PaymentGatewayEvent $record): string
    {
        return PaymentGatewayReconciliationReason::label($record->reconciliation_reason, $record->event_type);
    }

    public static function client(PaymentGatewayEvent $record): ?Client
    {
        $client = $record->transaction?->obligation?->client;

        return $client instanceof Client ? $client : null;
    }

    public static function product(PaymentGatewayEvent $record): string
    {
        $obligation = $record->transaction?->obligation;
        $service = $obligation?->booking?->service ?? $obligation?->service;

        if ($service instanceof Service) {
            return $service->name;
        }

        $item = $obligation?->purchase?->items?->first();
        $snapshot = $item?->product_snapshot;

        if (is_array($snapshot)) {
            foreach (['name', 'plan_name'] as $key) {
                if (is_string($snapshot[$key] ?? null) && filled($snapshot[$key])) {
                    return $snapshot[$key];
                }
            }
        }

        return 'Не сопоставлен';
    }
}
