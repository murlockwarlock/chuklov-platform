<?php

namespace App\Modules\B2B\Application;

use App\Modules\B2B\Domain\Enums\VideoMeetingOperation;
use App\Modules\B2B\Domain\ValueObjects\ProviderAccountAffinity;
use App\Modules\B2B\Jobs\ProcessBookingProviderSyncEvent;
use App\Modules\Integration\Application\RecordIntegrationEvent;
use App\Modules\Integration\Domain\Enums\IntegrationEventType;
use App\Modules\Integration\Domain\Models\IntegrationEvent;
use App\Modules\Integration\Domain\ValueObjects\IntegrationEventData;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordBookingProviderSyncEvent
{
    public function __construct(private readonly RecordIntegrationEvent $events) {}

    public function handle(
        Organization $organization,
        Booking $booking,
        VideoMeetingOperation $operation,
    ): IntegrationEvent {
        $affinity = $booking->providerAccountAffinity();
        if (! $affinity instanceof ProviderAccountAffinity) {
            throw ValidationException::withMessages([
                'provider' => 'Для синхронизации Zoom требуется полная привязка аккаунта и ведущего.',
            ]);
        }

        $version = (int) $booking->provider_sync_version;
        $event = $this->events->handle(
            organization: $organization,
            data: new IntegrationEventData(
                eventType: IntegrationEventType::BookingProviderSync,
                aggregateType: Booking::class,
                aggregateId: (int) $booking->getKey(),
                idempotencyKey: 'booking.provider_sync:'.$organization->getKey().':'.$booking->getKey().':'.$version.':'.$operation->value,
                payload: [
                    'organization_id' => (int) $organization->getKey(),
                    'booking_id' => (int) $booking->getKey(),
                    'operation' => $operation->value,
                    'event_version' => (int) $booking->event_version,
                    'provider_sync_version' => $version,
                    'provider' => (string) ($booking->provider_name ?? 'zoom'),
                    'provider_account_id' => $booking->provider_account_id,
                    'provider_host_user_id' => $booking->provider_host_user_id,
                    'provider_meeting_id' => $booking->provider_meeting_id,
                    'provider_meeting_uuid' => $booking->provider_meeting_uuid,
                    'provider_correlation_key' => $booking->provider_correlation_key,
                ],
                occurredAt: CarbonImmutable::now('UTC'),
            ),
        );

        DB::afterCommit(function () use ($event): void {
            ProcessBookingProviderSyncEvent::dispatch((int) $event->getKey())
                ->onConnection('redis')
                ->onQueue((string) config('b2b.queue'));
        });

        return $event;
    }
}
