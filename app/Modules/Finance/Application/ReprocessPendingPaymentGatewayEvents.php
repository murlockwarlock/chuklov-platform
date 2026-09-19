<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\PaymentGatewayEventStatus;
use App\Modules\Finance\Domain\Models\PaymentGatewayEvent;

final class ReprocessPendingPaymentGatewayEvents
{
    public function __construct(private readonly ProcessPaymentGatewayEvent $processor) {}

    public function handle(?int $limit = null): int
    {
        $now = now();
        $limit ??= max(1, (int) config('payments.events.batch_limit', 100));
        $eventIds = PaymentGatewayEvent::query()
            ->where(function ($query) use ($now): void {
                $query
                    ->where(function ($pending) use ($now): void {
                        $pending
                            ->where('processing_status', PaymentGatewayEventStatus::PendingLink->value)
                            ->where(function ($due) use ($now): void {
                                $due->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
                            });
                    })
                    ->orWhere(function ($processing) use ($now): void {
                        $processing
                            ->where('processing_status', PaymentGatewayEventStatus::Processing->value)
                            ->whereNotNull('lease_expires_at')
                            ->where('lease_expires_at', '<=', $now);
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $processed = 0;

        foreach ($eventIds as $eventId) {
            $event = PaymentGatewayEvent::query()->whereKey($eventId)->first();
            if ($event === null) {
                continue;
            }

            $result = $this->processor->handle((int) $event->organization_id, (int) $event->getKey());
            if ($result->processing_status === PaymentGatewayEventStatus::Processed) {
                $processed++;
            }
        }

        return $processed;
    }
}
