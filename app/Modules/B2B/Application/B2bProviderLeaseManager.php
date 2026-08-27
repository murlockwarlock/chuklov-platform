<?php

namespace App\Modules\B2B\Application;

use App\Modules\B2B\Domain\Enums\VideoMeetingOperation;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\B2B\Domain\ValueObjects\ProviderOperationLease;
use App\Modules\B2B\Domain\ValueObjects\ProviderOperationTiming;
use App\Modules\Integration\Domain\Enums\IntegrationEventStatus;
use App\Modules\Integration\Domain\Enums\IntegrationEventType;
use App\Modules\Integration\Domain\Models\IntegrationEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class B2bProviderLeaseManager
{
    public function __construct(private readonly B2bProviderMutationGuard $providerMutationGuard) {}

    public function claim(int $eventId): ?ProviderOperationLease
    {
        return DB::transaction(function () use ($eventId): ?ProviderOperationLease {
            $event = IntegrationEvent::query()->whereKey($eventId)->first();

            if (! $event instanceof IntegrationEvent
                || $event->getRawOriginal('event_type') !== IntegrationEventType::B2bSalesCallProviderSync->value) {
                return null;
            }

            $status = $event->getRawOriginal('status');
            if (in_array($status, [IntegrationEventStatus::Processed->value, IntegrationEventStatus::Failed->value], true)) {
                return null;
            }

            $operation = VideoMeetingOperation::tryFrom((string) ($event->payload['operation'] ?? ''));
            $callId = $this->positiveInt($event->payload['sales_call_id'] ?? null);
            if (! $operation instanceof VideoMeetingOperation || $callId === null) {
                $lockedEvent = IntegrationEvent::query()->whereKey($eventId)->lockForUpdate()->first();
                if ($lockedEvent instanceof IntegrationEvent
                    && $lockedEvent->getRawOriginal('event_type') === IntegrationEventType::B2bSalesCallProviderSync->value
                    && ! in_array($lockedEvent->getRawOriginal('status'), [IntegrationEventStatus::Processed->value, IntegrationEventStatus::Failed->value], true)) {
                    $lockedEvent->forceFill([
                        'status' => IntegrationEventStatus::Failed,
                        'processing_started_at' => null,
                        'processing_token' => null,
                        'updated_at' => now(),
                    ])->save();
                }

                return null;
            }

            $call = B2bSalesCall::query()
                ->where('organization_id', $event->organization_id)
                ->whereKey($callId)
                ->lockForUpdate()
                ->first();
            if (! $call instanceof B2bSalesCall) {
                $event = IntegrationEvent::query()->whereKey($eventId)->lockForUpdate()->first();
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

            $event = IntegrationEvent::query()
                ->where('organization_id', $call->organization_id)
                ->whereKey($eventId)
                ->lockForUpdate()
                ->first();
            if (! $event instanceof IntegrationEvent
                || $event->getRawOriginal('event_type') !== IntegrationEventType::B2bSalesCallProviderSync->value) {
                return null;
            }

            $status = $event->getRawOriginal('status');
            if (in_array($status, [IntegrationEventStatus::Processed->value, IntegrationEventStatus::Failed->value], true)) {
                return null;
            }

            $operation = VideoMeetingOperation::tryFrom((string) ($event->payload['operation'] ?? ''));
            $callId = $this->positiveInt($event->payload['sales_call_id'] ?? null);
            if (! $operation instanceof VideoMeetingOperation || $callId !== (int) $call->getKey()) {
                $event->forceFill([
                    'status' => IntegrationEventStatus::Failed,
                    'processing_started_at' => null,
                    'processing_token' => null,
                    'updated_at' => now(),
                ])->save();

                return null;
            }

            $claimNow = CarbonImmutable::now('UTC');
            $timing = ProviderOperationTiming::fromConfig();
            if ($this->hasActiveLeaseForAnotherEvent($call, (int) $event->getKey(), $claimNow)) {
                return null;
            }

            if (! $this->isAvailable($event, $status, $call, $claimNow)) {
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

            $eventToken = bin2hex(random_bytes(32));
            $leaseToken = bin2hex(random_bytes(32));
            $providerDeadlineExpiresAt = $timing->providerDeadlineExpiresAt($claimNow);
            $leaseExpiresAt = $timing->leaseExpiresAt($providerDeadlineExpiresAt);
            $eventVersion = (int) ($event->payload['event_version'] ?? 0);
            $providerSyncVersion = (int) ($event->payload['provider_sync_version'] ?? 0);

            $event->forceFill([
                'status' => IntegrationEventStatus::Processing,
                'attempt_count' => (int) $event->attempt_count + 1,
                'processing_started_at' => $claimNow,
                'processing_token' => $eventToken,
                'updated_at' => now(),
            ])->save();
            $call->forceFill([
                'provider_lease_token' => $leaseToken,
                'provider_lease_expires_at' => $leaseExpiresAt,
                'provider_lease_event_id' => $event->getKey(),
                'provider_lease_processing_token' => $eventToken,
            ])->save();

            return new ProviderOperationLease(
                organizationId: (int) $event->organization_id,
                salesCallId: $callId,
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

    public function owns(ProviderOperationLease $lease): bool
    {
        return DB::transaction(function () use ($lease): bool {
            $call = B2bSalesCall::query()
                ->where('organization_id', $lease->organizationId)
                ->whereKey($lease->salesCallId)
                ->lockForUpdate()
                ->first();
            $event = IntegrationEvent::query()
                ->where('organization_id', $lease->organizationId)
                ->whereKey($lease->eventId)
                ->lockForUpdate()
                ->first();

            return $event instanceof IntegrationEvent
                && $call instanceof B2bSalesCall
                && $event->getRawOriginal('status') === IntegrationEventStatus::Processing->value
                && $event->getRawOriginal('processing_token') === $lease->eventProcessingToken
                && (string) $call->provider_lease_token === $lease->leaseToken
                && (int) $call->provider_lease_event_id === $lease->eventId
                && (string) $call->provider_lease_processing_token === $lease->eventProcessingToken
                && (int) ($event->payload['event_version'] ?? 0) === $lease->eventVersion
                && (int) ($event->payload['provider_sync_version'] ?? 0) === $lease->providerSyncVersion
                && (int) $call->provider_sync_version === $lease->providerSyncVersion
                && $call->provider_operation === $lease->operation
                && $call->provider_lease_expires_at !== null
                && CarbonImmutable::parse((string) $call->provider_lease_expires_at)->greaterThan(CarbonImmutable::now('UTC'));
        });
    }

    private function isAvailable(
        IntegrationEvent $event,
        string $status,
        B2bSalesCall $call,
        CarbonImmutable $now,
    ): bool {
        if (in_array($status, [IntegrationEventStatus::Pending->value, IntegrationEventStatus::Retryable->value], true)) {
            return ! CarbonImmutable::parse((string) $event->available_at)->greaterThan($now);
        }

        if ($status !== IntegrationEventStatus::Processing->value) {
            return false;
        }

        $leaseForEventId = (int) $call->provider_lease_event_id === (int) $event->getKey();
        $leaseForEvent = $leaseForEventId
            && (string) $call->provider_lease_processing_token === (string) $event->processing_token;
        if ($leaseForEventId
            && $call->provider_lease_expires_at !== null
            && CarbonImmutable::parse((string) $call->provider_lease_expires_at)->greaterThan($now)) {
            return false;
        }

        $staleAt = $now->subSeconds((int) config('b2b.events.stale_after_seconds'));
        if (! $leaseForEvent
            && ($event->processing_started_at === null || $event->processing_started_at->greaterThan($staleAt))) {
            return false;
        }

        if ($leaseForEvent
            && $this->matchesCurrentGeneration($event, $call)
            && $call->provider_sync_status !== VideoMeetingSyncStatus::ReconciliationRequired) {
            $this->providerMutationGuard->markExpiredLeaseAsReconciliationRequired($call);
        }

        return true;
    }

    private function hasActiveLeaseForAnotherEvent(B2bSalesCall $call, int $eventId, CarbonImmutable $now): bool
    {
        return (int) $call->provider_lease_event_id !== 0
            && (int) $call->provider_lease_event_id !== $eventId
            && $call->provider_lease_expires_at !== null
            && CarbonImmutable::parse((string) $call->provider_lease_expires_at)->greaterThan($now);
    }

    private function matchesCurrentGeneration(IntegrationEvent $event, B2bSalesCall $call): bool
    {
        return (int) ($event->payload['event_version'] ?? 0) === (int) $call->event_version
            && (int) ($event->payload['provider_sync_version'] ?? 0) === (int) $call->provider_sync_version
            && VideoMeetingOperation::tryFrom((string) ($event->payload['operation'] ?? '')) === $call->provider_operation;
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
}
