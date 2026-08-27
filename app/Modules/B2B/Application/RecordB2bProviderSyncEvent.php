<?php

namespace App\Modules\B2B\Application;

use App\Modules\B2B\Domain\Enums\VideoMeetingOperation;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\B2B\Jobs\ProcessB2bProviderSyncEvent;
use App\Modules\Integration\Application\RecordIntegrationEvent;
use App\Modules\Integration\Domain\Enums\IntegrationEventType;
use App\Modules\Integration\Domain\Models\IntegrationEvent;
use App\Modules\Integration\Domain\ValueObjects\IntegrationEventData;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RecordB2bProviderSyncEvent
{
    public function __construct(private readonly RecordIntegrationEvent $events) {}

    public function handle(
        Organization $organization,
        B2bSalesCall $salesCall,
        VideoMeetingOperation $operation,
    ): IntegrationEvent {
        $version = (int) $salesCall->provider_sync_version;
        $event = $this->events->handle(
            organization: $organization,
            data: new IntegrationEventData(
                eventType: IntegrationEventType::B2bSalesCallProviderSync,
                aggregateType: B2bSalesCall::class,
                aggregateId: (int) $salesCall->getKey(),
                idempotencyKey: 'b2b.sales_call.provider_sync:'.$organization->getKey().':'.$salesCall->getKey().':'.$version.':'.$operation->value,
                payload: [
                    'organization_id' => (int) $organization->getKey(),
                    'sales_call_id' => (int) $salesCall->getKey(),
                    'lead_id' => (int) $salesCall->lead_id,
                    'operation' => $operation->value,
                    'event_version' => (int) $salesCall->event_version,
                    'provider_sync_version' => $version,
                    'provider' => (string) ($salesCall->provider_name ?? 'zoom'),
                    'provider_correlation_key' => $salesCall->provider_correlation_key,
                ],
                occurredAt: CarbonImmutable::now('UTC'),
            ),
        );

        DB::afterCommit(function () use ($event): void {
            ProcessB2bProviderSyncEvent::dispatch((int) $event->getKey())
                ->onQueue((string) config('b2b.queue'));
        });

        return $event;
    }
}
