<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\PaymentGatewayEventStatus;
use App\Modules\Finance\Domain\Models\PaymentGatewayEvent;

final class DrainPaymentGatewayEvents
{
    public function __construct(private readonly ProcessPaymentGatewayEvent $processor) {}

    public function handle(int $organizationId, string $gateway, string $providerReference): int
    {
        $eventIds = PaymentGatewayEvent::query()
            ->where('organization_id', $organizationId)
            ->where('gateway', $gateway)
            ->where('provider_reference', $providerReference)
            ->whereIn('processing_status', [
                PaymentGatewayEventStatus::PendingLink->value,
                PaymentGatewayEventStatus::Processing->value,
            ])
            ->orderBy('id')
            ->pluck('id');
        $processed = 0;

        foreach ($eventIds as $eventId) {
            $event = $this->processor->handle($organizationId, (int) $eventId);
            if ($event->processing_status === PaymentGatewayEventStatus::Processed) {
                $processed++;
            }
        }

        return $processed;
    }
}
