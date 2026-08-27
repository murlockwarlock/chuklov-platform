<?php

namespace App\Modules\B2B\Application;

use App\Modules\B2B\Domain\Contracts\VideoMeetingProvider;
use App\Modules\B2B\Domain\Enums\B2bSalesCallStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Enums\VideoMeetingOperation;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingIdentity;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingRequest;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingResult;
use App\Modules\B2B\Infrastructure\Video\VideoMeetingException;
use App\Modules\Integration\Domain\Enums\IntegrationEventStatus;
use App\Modules\Integration\Domain\Enums\IntegrationEventType;
use App\Modules\Integration\Domain\Models\IntegrationEvent;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class SyncB2bSalesCallProvider
{
    public function __construct(
        private readonly VideoMeetingProvider $provider,
        private readonly RecordScenarioEvent $scenarioEvents,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(int $eventId): void
    {
        $claim = $this->claim($eventId);

        if ($claim === null) {
            return;
        }

        [$organizationId, $token, $operation] = $claim;
        $event = IntegrationEvent::query()
            ->where('organization_id', $organizationId)
            ->whereKey($eventId)
            ->firstOrFail();
        $payload = $event->payload;
        $callId = $this->positiveInt($payload, 'sales_call_id');
        $call = B2bSalesCall::query()
            ->where('organization_id', $organizationId)
            ->whereKey($callId)
            ->firstOrFail();
        $organization = Organization::query()->findOrFail($organizationId);

        if (! $this->isCurrent($call, $payload, $operation)) {
            $this->markProcessed($eventId, $organizationId, $token);

            return;
        }

        if ($call->status === B2bSalesCallStatus::Cancelled && $operation !== VideoMeetingOperation::Cancel) {
            $this->markProcessed($eventId, $organizationId, $token);

            return;
        }

        $providerLock = Cache::lock(
            'b2b-provider-call:'.$organizationId.':'.$callId,
            max(30, (int) config('b2b.provider_lock_seconds')),
        );
        if (! $providerLock->get()) {
            $this->markFailure(
                eventId: $eventId,
                organizationId: $organizationId,
                token: $token,
                callId: $callId,
                payload: $payload,
                exception: VideoMeetingException::retryable('provider_busy'),
            );

            return;
        }

        try {
            $call = B2bSalesCall::query()
                ->where('organization_id', $organizationId)
                ->whereKey($callId)
                ->firstOrFail();

            if (! $this->isCurrent($call, $payload, $operation)) {
                $this->markProcessed($eventId, $organizationId, $token);

                return;
            }

            if ($call->status === B2bSalesCallStatus::Cancelled && $operation !== VideoMeetingOperation::Cancel) {
                $this->markProcessed($eventId, $organizationId, $token);

                return;
            }

            $result = $this->perform($organization, $call, $operation);

            if ($result instanceof VideoMeetingResult) {
                $active = $this->markReady(
                    eventId: $eventId,
                    organizationId: $organizationId,
                    token: $token,
                    callId: $callId,
                    payload: $payload,
                    result: $result,
                );

                if (! $active && in_array($operation, [VideoMeetingOperation::Create, VideoMeetingOperation::Recreate], true)) {
                    $this->provider->cancelMeeting($organization, $result->identity);
                }

                return;
            }

            $this->markCancelled(
                eventId: $eventId,
                organizationId: $organizationId,
                token: $token,
                callId: $callId,
                payload: $payload,
            );
        } catch (VideoMeetingException $exception) {
            $this->markFailure(
                eventId: $eventId,
                organizationId: $organizationId,
                token: $token,
                callId: $callId,
                payload: $payload,
                exception: $exception,
            );

            if ($exception->retryable) {
                return;
            }

            return;
        } catch (Throwable) {
            $exception = VideoMeetingException::retryable('provider_unexpected', true);
            $this->markFailure(
                eventId: $eventId,
                organizationId: $organizationId,
                token: $token,
                callId: $callId,
                payload: $payload,
                exception: $exception,
            );
        } finally {
            $providerLock->release();
        }
    }

    /** @return array{0: int, 1: string, 2: VideoMeetingOperation}|null */
    private function claim(int $eventId): ?array
    {
        return DB::transaction(function () use ($eventId): ?array {
            $event = IntegrationEvent::query()->whereKey($eventId)->lockForUpdate()->first();

            if (! $event instanceof IntegrationEvent
                || $event->getRawOriginal('event_type') !== IntegrationEventType::B2bSalesCallProviderSync->value) {
                return null;
            }

            $status = $event->getRawOriginal('status');
            if (in_array($status, [IntegrationEventStatus::Processed->value, IntegrationEventStatus::Failed->value], true)) {
                return null;
            }

            $now = CarbonImmutable::now('UTC');
            $staleAt = $now->subSeconds((int) config('b2b.events.stale_after_seconds'));
            if (in_array($status, [IntegrationEventStatus::Pending->value, IntegrationEventStatus::Retryable->value], true)) {
                if ($event->available_at->greaterThan($now)) {
                    return null;
                }
            } elseif ($status === IntegrationEventStatus::Processing->value) {
                if ($event->processing_started_at === null || $event->processing_started_at->greaterThan($staleAt)) {
                    return null;
                }
            } else {
                return null;
            }

            if ((int) $event->attempt_count >= (int) config('b2b.events.max_attempts')) {
                $event->forceFill(['status' => IntegrationEventStatus::Failed])->save();

                return null;
            }

            $operation = VideoMeetingOperation::tryFrom((string) ($event->payload['operation'] ?? ''));
            if (! $operation instanceof VideoMeetingOperation) {
                $event->forceFill(['status' => IntegrationEventStatus::Failed])->save();

                return null;
            }

            $token = bin2hex(random_bytes(32));
            $event->forceFill([
                'status' => IntegrationEventStatus::Processing,
                'attempt_count' => (int) $event->attempt_count + 1,
                'processing_started_at' => now(),
                'processing_token' => $token,
                'updated_at' => now(),
            ])->save();

            return [(int) $event->organization_id, $token, $operation];
        });
    }

    private function perform(
        Organization $organization,
        B2bSalesCall $call,
        VideoMeetingOperation $operation,
    ): ?VideoMeetingResult {
        if ($operation === VideoMeetingOperation::Cancel) {
            $identity = $call->providerIdentity();
            if ($identity instanceof VideoMeetingIdentity) {
                $this->provider->cancelMeeting($organization, $identity);
            } else {
                $existing = $this->provider->findMeeting($organization, $this->request($call));
                if ($existing instanceof VideoMeetingResult) {
                    $this->provider->cancelMeeting($organization, $existing->identity);
                }
            }

            return null;
        }

        $request = $this->request($call);
        $identity = $call->providerIdentity();
        if ($operation === VideoMeetingOperation::Update && $identity instanceof VideoMeetingIdentity) {
            $this->provider->updateMeeting($organization, $identity, $request);
            $joinUrl = (string) $call->provider_join_url;
            if ($joinUrl === '') {
                $existing = $this->provider->findMeeting($organization, $request);
                if (! $existing instanceof VideoMeetingResult) {
                    throw VideoMeetingException::reconciliationRequired('zoom_updated_meeting_unavailable');
                }

                return $existing;
            }

            return new VideoMeetingResult(
                identity: $identity,
                joinUrl: $joinUrl,
                synchronizedAt: CarbonImmutable::now('UTC'),
            );
        }

        if ($operation === VideoMeetingOperation::Recreate) {
            $meetingId = $call->provider_recreate_meeting_id;
            $identity = $call->providerIdentity();
            if (is_string($meetingId) && $meetingId !== '') {
                $identity = new VideoMeetingIdentity($meetingId, $call->provider_meeting_uuid);
            }
            if ($identity instanceof VideoMeetingIdentity) {
                $this->provider->cancelMeeting($organization, $identity);
            }

            $existing = $this->provider->findMeeting($organization, $request);
            if ($existing instanceof VideoMeetingResult) {
                if ($identity === null) {
                    $this->provider->cancelMeeting($organization, $existing->identity);
                } elseif ($existing->identity->meetingId !== $identity->meetingId) {
                    return $existing;
                } else {
                    throw VideoMeetingException::reconciliationRequired('zoom_recreate_old_meeting_present');
                }
            }

            return $this->provider->createMeeting($organization, $request);
        }

        $existing = $this->provider->findMeeting($organization, $request);
        if ($existing instanceof VideoMeetingResult) {
            if (in_array($operation, [VideoMeetingOperation::Create, VideoMeetingOperation::Update], true)) {
                $this->provider->updateMeeting($organization, $existing->identity, $request);

                return new VideoMeetingResult(
                    identity: $existing->identity,
                    joinUrl: $existing->joinUrl,
                    synchronizedAt: CarbonImmutable::now('UTC'),
                );
            }

            return $existing;
        }

        return $this->provider->createMeeting($organization, $request);
    }

    private function request(B2bSalesCall $call): VideoMeetingRequest
    {
        return new VideoMeetingRequest(
            externalKey: 'b2b-sales-call:'.$call->organization_id.':'.$call->getKey(),
            startsAt: $call->startsAtUtc(),
            durationMinutes: max(1, (int) round($call->startsAtUtc()->diffInMinutes($call->endsAtUtc()))),
            timezone: (string) $call->schedule_timezone,
            topic: (string) config('b2b.zoom.topic'),
        );
    }

    /** @param array<string, mixed> $payload */
    private function isCurrent(B2bSalesCall $call, array $payload, VideoMeetingOperation $operation): bool
    {
        return (int) ($payload['organization_id'] ?? 0) === (int) $call->organization_id
            && (int) ($payload['sales_call_id'] ?? 0) === (int) $call->getKey()
            && (int) ($payload['event_version'] ?? 0) === (int) $call->event_version
            && (int) ($payload['provider_sync_version'] ?? 0) === (int) $call->provider_sync_version
            && $operation === $call->provider_operation;
    }

    /** @param array<string, mixed> $payload */
    private function markReady(
        int $eventId,
        int $organizationId,
        string $token,
        int $callId,
        array $payload,
        VideoMeetingResult $result,
    ): bool {
        return DB::transaction(function () use ($eventId, $organizationId, $token, $callId, $payload, $result): bool {
            $call = B2bSalesCall::query()
                ->where('organization_id', $organizationId)
                ->whereKey($callId)
                ->lockForUpdate()
                ->firstOrFail();
            $active = (int) ($payload['event_version'] ?? 0) === (int) $call->event_version
                && (int) ($payload['provider_sync_version'] ?? 0) === (int) $call->provider_sync_version
                && $call->status === B2bSalesCallStatus::Scheduled;

            if ($active) {
                $call->forceFill([
                    'provider_name' => $this->provider->name(),
                    'provider_meeting_id' => $result->identity->meetingId,
                    'provider_meeting_uuid' => $result->identity->meetingUuid,
                    'provider_join_url' => $result->joinUrl,
                    'provider_sync_status' => VideoMeetingSyncStatus::Ready,
                    'provider_operation' => null,
                    'provider_synced_at' => $result->synchronizedAt,
                    'provider_error_code' => null,
                    'provider_recreate_meeting_id' => null,
                ])->save();
                $this->scenarioEvents->b2bSalesCallReady($call, $result->synchronizedAt);
                $this->audit->handle(
                    organization: $call->organization,
                    actor: null,
                    action: 'b2b.sales_call.provider_sync.updated',
                    targetType: B2bSalesCall::class,
                    targetId: (string) $call->getKey(),
                    metadata: [
                        'operation' => (string) ($payload['operation'] ?? ''),
                        'status' => VideoMeetingSyncStatus::Ready->value,
                        'provider' => $this->provider->name(),
                        'error_code' => null,
                    ],
                );
            }

            $this->markProcessed($eventId, $organizationId, $token);

            return $active;
        });
    }

    /** @param array<string, mixed> $payload */
    private function markCancelled(
        int $eventId,
        int $organizationId,
        string $token,
        int $callId,
        array $payload,
    ): void {
        DB::transaction(function () use ($eventId, $organizationId, $token, $callId, $payload): void {
            $call = B2bSalesCall::query()
                ->where('organization_id', $organizationId)
                ->whereKey($callId)
                ->lockForUpdate()
                ->firstOrFail();
            $isCurrent = (int) ($payload['event_version'] ?? 0) === (int) $call->event_version
                && (int) ($payload['provider_sync_version'] ?? 0) === (int) $call->provider_sync_version
                && $call->provider_operation === VideoMeetingOperation::Cancel;
            if ($isCurrent && ($call->status === B2bSalesCallStatus::Cancelled || $call->meeting_mode === VideoMeetingMode::Manual)) {
                $call->forceFill([
                    'provider_sync_status' => VideoMeetingSyncStatus::NotRequired,
                    'provider_operation' => null,
                    'provider_synced_at' => now(),
                    'provider_error_code' => null,
                    'provider_name' => null,
                    'provider_meeting_id' => null,
                    'provider_meeting_uuid' => null,
                    'provider_join_url' => null,
                    'provider_recreate_meeting_id' => null,
                ])->save();
            }
            if ($isCurrent) {
                $this->audit->handle(
                    organization: $call->organization,
                    actor: null,
                    action: 'b2b.sales_call.provider_sync.updated',
                    targetType: B2bSalesCall::class,
                    targetId: (string) $call->getKey(),
                    metadata: [
                        'operation' => (string) ($payload['operation'] ?? ''),
                        'status' => VideoMeetingSyncStatus::NotRequired->value,
                        'provider' => $this->provider->name(),
                        'error_code' => null,
                    ],
                );
            }
            $this->markProcessed($eventId, $organizationId, $token);
        });
    }

    /** @param array<string, mixed> $payload */
    private function markFailure(
        int $eventId,
        int $organizationId,
        string $token,
        int $callId,
        array $payload,
        VideoMeetingException $exception,
    ): void {
        DB::transaction(function () use ($eventId, $organizationId, $token, $callId, $payload, $exception): void {
            $call = B2bSalesCall::query()
                ->where('organization_id', $organizationId)
                ->whereKey($callId)
                ->lockForUpdate()
                ->first();
            if ($call instanceof B2bSalesCall
                && (int) ($payload['provider_sync_version'] ?? 0) === (int) $call->provider_sync_version) {
                $status = $exception->outcomeUnknown
                    ? VideoMeetingSyncStatus::ReconciliationRequired
                    : VideoMeetingSyncStatus::Failed;
                $call->forceFill([
                    'provider_sync_status' => $status,
                    'provider_error_code' => $exception->safeCode,
                ])->save();
                $this->audit->handle(
                    organization: $call->organization,
                    actor: null,
                    action: 'b2b.sales_call.provider_sync.updated',
                    targetType: B2bSalesCall::class,
                    targetId: (string) $call->getKey(),
                    metadata: [
                        'operation' => (string) ($payload['operation'] ?? ''),
                        'status' => $status->value,
                        'provider' => $this->provider->name(),
                        'error_code' => $exception->safeCode,
                    ],
                );
            }

            $event = IntegrationEvent::query()
                ->where('organization_id', $organizationId)
                ->whereKey($eventId)
                ->where('status', IntegrationEventStatus::Processing->value)
                ->where('processing_token', $token)
                ->lockForUpdate()
                ->first();
            if (! $event instanceof IntegrationEvent) {
                return;
            }
            if (! $exception->retryable || (int) $event->attempt_count >= (int) config('b2b.events.max_attempts')) {
                $event->forceFill([
                    'status' => IntegrationEventStatus::Failed,
                    'processing_started_at' => null,
                    'processing_token' => null,
                    'updated_at' => now(),
                ])->save();

                return;
            }
            $event->forceFill([
                'status' => IntegrationEventStatus::Retryable,
                'available_at' => now()->addSeconds((int) config('b2b.events.retry_after_seconds')),
                'processing_started_at' => null,
                'processing_token' => null,
                'updated_at' => now(),
            ])->save();
        });
    }

    private function markProcessed(int $eventId, int $organizationId, string $token): void
    {
        IntegrationEvent::query()
            ->where('organization_id', $organizationId)
            ->whereKey($eventId)
            ->where('status', IntegrationEventStatus::Processing->value)
            ->where('processing_token', $token)
            ->update([
                'status' => IntegrationEventStatus::Processed->value,
                'processed_at' => now(),
                'processing_started_at' => null,
                'processing_token' => null,
                'updated_at' => now(),
            ]);
    }

    /** @param array<string, mixed> $payload */
    private function positiveInt(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new InvalidArgumentException('The B2B provider event identifier is invalid.');
    }
}
