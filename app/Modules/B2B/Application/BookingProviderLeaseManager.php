<?php

namespace App\Modules\B2B\Application;

use App\Modules\B2B\Domain\Enums\VideoMeetingOperation;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\ValueObjects\BookingProviderOperationLease;
use App\Modules\B2B\Domain\ValueObjects\ProviderOperationTiming;
use App\Modules\Integration\Domain\Enums\IntegrationEventStatus;
use App\Modules\Integration\Domain\Enums\IntegrationEventType;
use App\Modules\Integration\Domain\Models\IntegrationEvent;
use App\Modules\Scheduling\Domain\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class BookingProviderLeaseManager
{
    public function claim(int $eventId): ?BookingProviderOperationLease
    {
        return DB::transaction(function () use ($eventId): ?BookingProviderOperationLease {
            $event = IntegrationEvent::query()->find($eventId);
            if (! $event instanceof IntegrationEvent
                || $event->getRawOriginal('event_type') !== IntegrationEventType::BookingProviderSync->value) {
                return null;
            }

            $operation = VideoMeetingOperation::tryFrom((string) ($event->payload['operation'] ?? ''));
            $bookingId = $this->positiveInt($event->payload['booking_id'] ?? null);
            if (! $operation instanceof VideoMeetingOperation || $bookingId === null) {
                $this->failMalformedEvent($eventId);

                return null;
            }

            $booking = Booking::query()
                ->where('organization_id', $event->organization_id)
                ->whereKey($bookingId)
                ->lockForUpdate()
                ->first();
            $event = IntegrationEvent::query()
                ->where('organization_id', $event->organization_id)
                ->whereKey($eventId)
                ->lockForUpdate()
                ->first();
            if (! $booking instanceof Booking || ! $event instanceof IntegrationEvent) {
                if ($event instanceof IntegrationEvent) {
                    $event->forceFill([
                        'status' => IntegrationEventStatus::Failed,
                        'processing_started_at' => null,
                        'processing_token' => null,
                        'updated_at' => now(),
                    ])->save();
                }

                return null;
            }

            $status = $event->getRawOriginal('status');
            if (in_array($status, [IntegrationEventStatus::Processed->value, IntegrationEventStatus::Failed->value], true)) {
                return null;
            }

            $now = CarbonImmutable::now('UTC');
            if ($this->hasAnotherActiveLease($booking, $eventId, $now)) {
                return null;
            }

            if (! $this->available($event, $status, $booking, $now)) {
                return null;
            }

            if ((int) $event->attempt_count >= (int) config('b2b.events.max_attempts')) {
                $event->forceFill([
                    'status' => IntegrationEventStatus::Failed,
                    'processing_started_at' => null,
                    'processing_token' => null,
                    'updated_at' => now(),
                ])->save();

                return null;
            }

            $timing = ProviderOperationTiming::fromConfig();
            $eventToken = bin2hex(random_bytes(32));
            $leaseToken = bin2hex(random_bytes(32));
            $providerDeadlineExpiresAt = $timing->providerDeadlineExpiresAt($now);
            $leaseExpiresAt = $timing->leaseExpiresAt($providerDeadlineExpiresAt);
            $eventVersion = (int) ($event->payload['event_version'] ?? 0);
            $providerSyncVersion = (int) ($event->payload['provider_sync_version'] ?? 0);

            $event->forceFill([
                'status' => IntegrationEventStatus::Processing,
                'attempt_count' => (int) $event->attempt_count + 1,
                'processing_started_at' => $now,
                'processing_token' => $eventToken,
                'updated_at' => now(),
            ])->save();
            $booking->forceFill([
                'provider_lease_token' => $leaseToken,
                'provider_lease_expires_at' => $leaseExpiresAt,
                'provider_lease_event_id' => $event->getKey(),
                'provider_lease_processing_token' => $eventToken,
            ])->save();

            return new BookingProviderOperationLease(
                organizationId: (int) $event->organization_id,
                bookingId: $bookingId,
                eventId: (int) $event->getKey(),
                eventProcessingToken: $eventToken,
                leaseToken: $leaseToken,
                eventVersion: $eventVersion,
                providerSyncVersion: $providerSyncVersion,
                operation: $operation,
                providerDeadlineExpiresAt: $providerDeadlineExpiresAt,
                leaseExpiresAt: $leaseExpiresAt,
                requestSafetySeconds: $timing->requestSafetySeconds,
            );
        });
    }

    public function owns(BookingProviderOperationLease $lease): bool
    {
        return DB::transaction(function () use ($lease): bool {
            $booking = Booking::query()
                ->where('organization_id', $lease->organizationId)
                ->whereKey($lease->bookingId)
                ->lockForUpdate()
                ->first();
            $event = IntegrationEvent::query()
                ->where('organization_id', $lease->organizationId)
                ->whereKey($lease->eventId)
                ->lockForUpdate()
                ->first();

            return $booking instanceof Booking
                && $event instanceof IntegrationEvent
                && $event->getRawOriginal('status') === IntegrationEventStatus::Processing->value
                && $event->getRawOriginal('processing_token') === $lease->eventProcessingToken
                && (string) $booking->provider_lease_token === $lease->leaseToken
                && (int) $booking->provider_lease_event_id === $lease->eventId
                && (string) $booking->provider_lease_processing_token === $lease->eventProcessingToken
                && (int) ($event->payload['event_version'] ?? 0) === $lease->eventVersion
                && (int) ($event->payload['provider_sync_version'] ?? 0) === $lease->providerSyncVersion
                && (int) $booking->provider_sync_version === $lease->providerSyncVersion
                && $booking->provider_operation === $lease->operation
                && $booking->provider_lease_expires_at !== null
                && CarbonImmutable::parse((string) $booking->provider_lease_expires_at)->greaterThan(CarbonImmutable::now('UTC'));
        });
    }

    private function available(
        IntegrationEvent $event,
        string $status,
        Booking $booking,
        CarbonImmutable $now,
    ): bool {
        if (in_array($status, [IntegrationEventStatus::Pending->value, IntegrationEventStatus::Retryable->value], true)) {
            return ! CarbonImmutable::parse((string) $event->available_at)->greaterThan($now);
        }

        if ($status !== IntegrationEventStatus::Processing->value) {
            return false;
        }

        if ((int) $booking->provider_lease_event_id === (int) $event->getKey()
            && $booking->provider_lease_expires_at !== null
            && CarbonImmutable::parse((string) $booking->provider_lease_expires_at)->greaterThan($now)) {
            return false;
        }

        $staleAt = $now->subSeconds((int) config('b2b.events.stale_after_seconds'));
        if ($event->processing_started_at !== null
            && CarbonImmutable::parse((string) $event->processing_started_at)->greaterThan($staleAt)) {
            return false;
        }

        if ((int) $booking->provider_lease_event_id === (int) $event->getKey()) {
            $booking->forceFill([
                'provider_sync_status' => VideoMeetingSyncStatus::ReconciliationRequired,
                'provider_error_code' => 'provider_worker_lost',
                ...$this->clearLease(),
            ])->save();
        }

        return true;
    }

    private function hasAnotherActiveLease(Booking $booking, int $eventId, CarbonImmutable $now): bool
    {
        return (int) $booking->provider_lease_event_id !== 0
            && (int) $booking->provider_lease_event_id !== $eventId
            && $booking->provider_lease_expires_at !== null
            && CarbonImmutable::parse((string) $booking->provider_lease_expires_at)->greaterThan($now);
    }

    private function failMalformedEvent(int $eventId): void
    {
        $event = IntegrationEvent::query()->whereKey($eventId)->lockForUpdate()->first();
        if ($event instanceof IntegrationEvent
            && ! in_array($event->getRawOriginal('status'), [IntegrationEventStatus::Processed->value, IntegrationEventStatus::Failed->value], true)) {
            $event->forceFill([
                'status' => IntegrationEventStatus::Failed,
                'processing_started_at' => null,
                'processing_token' => null,
                'updated_at' => now(),
            ])->save();
        }

    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /** @return array{provider_lease_token: null, provider_lease_expires_at: null, provider_lease_event_id: null, provider_lease_processing_token: null} */
    private function clearLease(): array
    {
        return [
            'provider_lease_token' => null,
            'provider_lease_expires_at' => null,
            'provider_lease_event_id' => null,
            'provider_lease_processing_token' => null,
        ];
    }
}
