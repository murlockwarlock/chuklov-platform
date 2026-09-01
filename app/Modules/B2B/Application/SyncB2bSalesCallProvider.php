<?php

namespace App\Modules\B2B\Application;

use App\Modules\B2B\Domain\Contracts\VideoMeetingProvider;
use App\Modules\B2B\Domain\Enums\B2bSalesCallStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Enums\VideoMeetingOperation;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\B2B\Domain\ValueObjects\ProviderOperationDeadline;
use App\Modules\B2B\Domain\ValueObjects\ProviderOperationLease;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingIdentity;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingRequest;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingResult;
use App\Modules\B2B\Infrastructure\Video\VideoMeetingException;
use App\Modules\Integration\Domain\Enums\IntegrationEventStatus;
use App\Modules\Integration\Domain\Models\IntegrationEvent;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class SyncB2bSalesCallProvider
{
    public function __construct(
        private readonly VideoMeetingProvider $provider,
        private readonly RecordScenarioEvent $scenarioEvents,
        private readonly RecordAuditEvent $audit,
        private readonly B2bProviderLeaseManager $leases,
        private readonly RecordB2bProviderSyncEvent $providerEvents,
    ) {}

    public function handle(int $eventId): void
    {
        $lease = $this->leases->claim($eventId);

        if (! $lease instanceof ProviderOperationLease) {
            return;
        }

        $event = $this->event($lease);
        $payload = $event->payload;
        $call = $this->call($lease);
        $organization = Organization::query()->findOrFail($lease->organizationId);
        $deadline = $lease->providerDeadline();

        if (! $this->isCurrent($call, $payload, $lease->operation)
            || ($call->status === B2bSalesCallStatus::Cancelled && $lease->operation !== VideoMeetingOperation::Cancel)) {
            $this->markProcessed($lease);

            return;
        }

        try {
            if ($lease->operation === VideoMeetingOperation::Recreate) {
                $this->recreate($organization, $call, $lease, $deadline);
                $this->transitionRecreateToCreate($organization, $lease, $payload);

                return;
            }

            $result = $this->perform($organization, $call, $lease, $deadline);

            if ($result instanceof VideoMeetingResult) {
                $this->assertResultForCall($call, $lease->operation, $result);
                $active = $this->markReady($lease, $payload, $result);

                if (! $active
                    && in_array($lease->operation, [VideoMeetingOperation::Create, VideoMeetingOperation::Recreate], true)
                    && $this->leases->owns($lease)
                    && $deadline->canStart()) {
                    $this->provider->cancelMeeting(
                        $organization,
                        $result->identity,
                        $this->requestForCorrelation($call, $payload['provider_correlation_key'] ?? null),
                        $deadline,
                    );
                }

                return;
            }

            $this->markCancelled($lease, $payload);
        } catch (ProviderLeaseLost) {
            return;
        } catch (VideoMeetingException $exception) {
            $this->markFailure($lease, $payload, $exception);
        } catch (Throwable) {
            $this->markFailure(
                $lease,
                $payload,
                VideoMeetingException::retryable('provider_unexpected', true),
            );
        }
    }

    private function perform(
        Organization $organization,
        B2bSalesCall $call,
        ProviderOperationLease $lease,
        ProviderOperationDeadline $deadline,
    ): ?VideoMeetingResult {
        return match ($lease->operation) {
            VideoMeetingOperation::Create => $this->create($organization, $call, $lease, $deadline),
            VideoMeetingOperation::Update => $this->update($organization, $call, $lease, $deadline),
            VideoMeetingOperation::Cancel => $this->cancel($organization, $call, $lease, $deadline),
            VideoMeetingOperation::Recreate => throw new \LogicException('Recreate is handled before provider operation dispatch.'),
            VideoMeetingOperation::Reconcile => $this->reconcile($organization, $call, $lease, $deadline),
        };
    }

    private function create(
        Organization $organization,
        B2bSalesCall $call,
        ProviderOperationLease $lease,
        ProviderOperationDeadline $deadline,
    ): VideoMeetingResult {
        if ($call->providerIdentity() instanceof VideoMeetingIdentity) {
            throw VideoMeetingException::reconciliationRequired('zoom_create_known_identity');
        }

        $request = $this->request($call);
        $existing = $this->provider->findMeeting($organization, $request, $deadline);
        if ($existing instanceof VideoMeetingResult) {
            $this->assertCorrelatedRemote($existing, $request);
            if (! $existing->matchesRequest($request)) {
                return $this->repairSchedule(
                    organization: $organization,
                    identity: $existing->identity,
                    request: $request,
                    lease: $lease,
                    deadline: $deadline,
                );
            }

            return $existing;
        }

        if ($call->provider_sync_status === VideoMeetingSyncStatus::ReconciliationRequired) {
            throw VideoMeetingException::reconciliationRequired('zoom_create_unresolved');
        }

        $this->assertCurrentGeneration($lease);

        return $this->provider->createMeeting($organization, $request, $deadline);
    }

    private function update(
        Organization $organization,
        B2bSalesCall $call,
        ProviderOperationLease $lease,
        ProviderOperationDeadline $deadline,
    ): VideoMeetingResult {
        $identity = $call->providerIdentity();
        if (! $identity instanceof VideoMeetingIdentity) {
            throw VideoMeetingException::reconciliationRequired('zoom_update_identity_missing');
        }

        $request = $this->request($call);
        if ($call->provider_sync_status === VideoMeetingSyncStatus::ReconciliationRequired) {
            return $this->reconcileUpdate($organization, $identity, $request, $lease, $deadline);
        }

        $this->assertCurrentGeneration($lease);
        $remote = $this->provider->getMeeting($organization, $identity, $request, $deadline);
        if (! $remote instanceof VideoMeetingResult) {
            throw VideoMeetingException::reconciliationRequired('zoom_update_identity_missing');
        }
        $this->assertExpectedRemote($remote, $identity, $request);

        try {
            $this->assertCurrentGeneration($lease);
            $this->provider->updateMeeting($organization, $identity, $request, $deadline);
        } catch (VideoMeetingException $exception) {
            if (! $exception->outcomeUnknown && ! $exception->requiresReconciliation) {
                throw $exception;
            }

            return $this->reconcileUpdate($organization, $identity, $request, $lease, $deadline);
        }

        $remote = $this->readAndVerifyRemote($organization, $identity, $request, $lease, $deadline);

        return $this->resultWithJoinUrl(
            identity: $identity,
            request: $request,
            organization: $organization,
            deadline: $deadline,
            remote: $remote,
        );
    }

    private function reconcileUpdate(
        Organization $organization,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
        ProviderOperationLease $lease,
        ProviderOperationDeadline $deadline,
    ): VideoMeetingResult {
        $remote = $this->provider->getMeeting($organization, $identity, $request, $deadline);
        if (! $remote instanceof VideoMeetingResult) {
            throw VideoMeetingException::reconciliationRequired('zoom_update_identity_missing');
        }

        $this->assertExpectedRemote($remote, $identity, $request);

        if (! $remote->matchesRequest($request)) {
            $remote = $this->repairSchedule(
                organization: $organization,
                identity: $identity,
                request: $request,
                lease: $lease,
                deadline: $deadline,
            );
        }

        return $this->resultWithJoinUrl(
            identity: $identity,
            request: $request,
            organization: $organization,
            deadline: $deadline,
            remote: $remote,
        );
    }

    private function cancel(
        Organization $organization,
        B2bSalesCall $call,
        ProviderOperationLease $lease,
        ProviderOperationDeadline $deadline,
    ): null {
        if ($call->hasIncompleteProviderRecreatePair()) {
            throw VideoMeetingException::reconciliationRequired('provider_recreate_pair_invalid');
        }

        $identity = $this->cleanupIdentity($call);
        if ($identity instanceof VideoMeetingIdentity) {
            $request = $this->cleanupRequest($call);
            $this->assertCurrentGeneration($lease);
            $this->provider->cancelMeeting($organization, $identity, $request, $deadline);

            return null;
        }

        $request = $this->request($call);
        $existing = $this->provider->findMeeting($organization, $request, $deadline);
        if ($existing instanceof VideoMeetingResult) {
            $this->assertCorrelatedRemote($existing, $request);
            $this->assertCurrentGeneration($lease);
            $this->provider->cancelMeeting($organization, $existing->identity, $request, $deadline);

            return null;
        }

        if ($call->provider_sync_status === VideoMeetingSyncStatus::ReconciliationRequired) {
            throw VideoMeetingException::reconciliationRequired('zoom_cancel_unresolved');
        }

        return null;
    }

    private function recreate(
        Organization $organization,
        B2bSalesCall $call,
        ProviderOperationLease $lease,
        ProviderOperationDeadline $deadline,
    ): void {
        if ($call->hasIncompleteProviderRecreatePair()) {
            throw VideoMeetingException::reconciliationRequired('provider_recreate_pair_invalid');
        }

        $oldIdentity = $this->recreateIdentity($call);
        if (! $oldIdentity instanceof VideoMeetingIdentity) {
            throw VideoMeetingException::reconciliationRequired('zoom_recreate_identity_missing');
        }

        $oldRequest = $this->recreateRequest($call);
        $this->assertCurrentGeneration($lease);
        $this->provider->cancelMeeting($organization, $oldIdentity, $oldRequest, $deadline);
    }

    /** @param array<string, mixed> $payload */
    private function transitionRecreateToCreate(
        Organization $organization,
        ProviderOperationLease $lease,
        array $payload,
    ): void {
        DB::transaction(function () use ($organization, $lease, $payload): void {
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

            if (! $call instanceof B2bSalesCall
                || ! $event instanceof IntegrationEvent
                || $event->getRawOriginal('status') !== IntegrationEventStatus::Processing->value
                || $event->getRawOriginal('processing_token') !== $lease->eventProcessingToken
                || ! $this->leaseIsActive($call, $lease)
                || ! $this->isCurrent($call, $payload, VideoMeetingOperation::Recreate)) {
                return;
            }

            $call->forceFill([
                'provider_meeting_id' => null,
                'provider_meeting_uuid' => null,
                'provider_join_url' => null,
                'provider_sync_status' => VideoMeetingSyncStatus::Pending,
                'provider_operation' => VideoMeetingOperation::Create,
                'provider_synced_at' => null,
                'provider_error_code' => null,
                'provider_recreate_meeting_id' => null,
                'provider_recreate_correlation_key' => null,
                'provider_sync_version' => (int) $call->provider_sync_version + 1,
                'event_version' => (int) $call->event_version + 1,
                ...$this->clearLease(),
            ])->save();
            $this->providerEvents->handle($organization, $call, VideoMeetingOperation::Create);
            $this->audit->handle(
                organization: $organization,
                actor: null,
                action: 'b2b.sales_call.provider_sync.updated',
                targetType: B2bSalesCall::class,
                targetId: (string) $call->getKey(),
                metadata: [
                    'operation' => VideoMeetingOperation::Create->value,
                    'status' => VideoMeetingSyncStatus::Pending->value,
                    'provider' => $this->provider->name(),
                    'error_code' => null,
                ],
            );
            $event->forceFill([
                'status' => IntegrationEventStatus::Processed,
                'processed_at' => now(),
                'processing_started_at' => null,
                'processing_token' => null,
                'updated_at' => now(),
            ])->save();
        });
    }

    private function reconcile(
        Organization $organization,
        B2bSalesCall $call,
        ProviderOperationLease $lease,
        ProviderOperationDeadline $deadline,
    ): VideoMeetingResult {
        $identity = $call->providerIdentity();
        if ($identity instanceof VideoMeetingIdentity) {
            $request = $this->request($call);
            $remote = $this->provider->getMeeting($organization, $identity, $request, $deadline);

            if ($remote instanceof VideoMeetingResult) {
                $this->assertExpectedRemote($remote, $identity, $request);
                if (! $remote->matchesRequest($request)) {
                    $remote = $this->repairSchedule(
                        organization: $organization,
                        identity: $identity,
                        request: $request,
                        lease: $lease,
                        deadline: $deadline,
                    );
                }

                return $remote;
            }

            throw VideoMeetingException::reconciliationRequired('zoom_identity_missing');
        }

        $request = $this->request($call);
        $remote = $this->provider->findMeeting($organization, $request, $deadline);
        if (! $remote instanceof VideoMeetingResult) {
            throw VideoMeetingException::reconciliationRequired('zoom_correlation_unresolved');
        }

        $this->assertCorrelatedRemote($remote, $request);
        if (! $remote->matchesRequest($request)) {
            $remote = $this->repairSchedule(
                organization: $organization,
                identity: $remote->identity,
                request: $request,
                lease: $lease,
                deadline: $deadline,
            );
        }

        return $remote;
    }

    private function repairSchedule(
        Organization $organization,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
        ProviderOperationLease $lease,
        ProviderOperationDeadline $deadline,
    ): VideoMeetingResult {
        $this->assertCurrentGeneration($lease);
        $this->provider->updateMeeting($organization, $identity, $request, $deadline);

        return $this->readAndVerifyRemote($organization, $identity, $request, $lease, $deadline);
    }

    private function readAndVerifyRemote(
        Organization $organization,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
        ProviderOperationLease $lease,
        ProviderOperationDeadline $deadline,
    ): VideoMeetingResult {
        $this->assertCurrentGeneration($lease);
        $remote = $this->provider->getMeeting($organization, $identity, $request, $deadline);
        if (! $remote instanceof VideoMeetingResult) {
            throw VideoMeetingException::reconciliationRequired('zoom_update_identity_missing');
        }

        $this->assertExpectedRemote($remote, $identity, $request);
        $this->assertRequestSchedule($remote, $request);

        return $remote;
    }

    private function resultWithJoinUrl(
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
        Organization $organization,
        ProviderOperationDeadline $deadline,
        ?VideoMeetingResult $remote = null,
    ): VideoMeetingResult {
        $remote ??= $this->provider->getMeeting($organization, $identity, $request, $deadline);
        if (! $remote instanceof VideoMeetingResult) {
            throw VideoMeetingException::reconciliationRequired('zoom_join_url_missing');
        }

        $this->assertExpectedRemote($remote, $identity, $request);

        return new VideoMeetingResult(
            identity: $identity,
            joinUrl: $remote->joinUrl,
            synchronizedAt: CarbonImmutable::now('UTC'),
            startsAt: $remote->startsAt,
            durationMinutes: $remote->durationMinutes,
            timezone: $remote->timezone,
            agenda: $remote->agenda,
        );
    }

    private function event(ProviderOperationLease $lease): IntegrationEvent
    {
        return IntegrationEvent::query()
            ->where('organization_id', $lease->organizationId)
            ->whereKey($lease->eventId)
            ->firstOrFail();
    }

    private function call(ProviderOperationLease $lease): B2bSalesCall
    {
        return B2bSalesCall::query()
            ->where('organization_id', $lease->organizationId)
            ->whereKey($lease->salesCallId)
            ->firstOrFail();
    }

    private function request(B2bSalesCall $call): VideoMeetingRequest
    {
        return $this->requestForCorrelation($call, $call->provider_correlation_key);
    }

    private function requestForCorrelation(B2bSalesCall $call, mixed $correlationKey): VideoMeetingRequest
    {
        if (! is_string($correlationKey) || trim($correlationKey) === '') {
            throw VideoMeetingException::reconciliationRequired('provider_correlation_missing');
        }

        try {
            $durationMinutes = $call->exactDuration()->minutes;
        } catch (InvalidArgumentException) {
            throw VideoMeetingException::reconciliationRequired('sales_call_interval_invalid');
        }

        return new VideoMeetingRequest(
            externalKey: $correlationKey,
            startsAt: $call->startsAtUtc(),
            durationMinutes: $durationMinutes,
            timezone: (string) $call->schedule_timezone,
            topic: (string) config('b2b.zoom.topic'),
        );
    }

    private function recreateIdentity(B2bSalesCall $call): ?VideoMeetingIdentity
    {
        $meetingId = $call->provider_recreate_meeting_id;
        if (is_string($meetingId) && trim($meetingId) !== '') {
            return new VideoMeetingIdentity($meetingId, $call->provider_meeting_uuid);
        }

        return $call->providerIdentity();
    }

    private function recreateRequest(B2bSalesCall $call): VideoMeetingRequest
    {
        if (is_string($call->provider_recreate_correlation_key)
            && trim($call->provider_recreate_correlation_key) !== '') {
            return $this->requestForCorrelation($call, $call->provider_recreate_correlation_key);
        }

        $hasRecreateIdentity = is_string($call->provider_recreate_meeting_id)
            && trim($call->provider_recreate_meeting_id) !== '';

        if ($hasRecreateIdentity) {
            throw VideoMeetingException::reconciliationRequired('provider_recreate_correlation_missing');
        }

        return $this->request($call);
    }

    private function cleanupIdentity(B2bSalesCall $call): ?VideoMeetingIdentity
    {
        return $this->recreateIdentity($call);
    }

    private function cleanupRequest(B2bSalesCall $call): VideoMeetingRequest
    {
        $hasRecreateIdentity = is_string($call->provider_recreate_meeting_id)
            && trim($call->provider_recreate_meeting_id) !== '';

        return $hasRecreateIdentity ? $this->recreateRequest($call) : $this->request($call);
    }

    private function assertResultForCall(
        B2bSalesCall $call,
        VideoMeetingOperation $operation,
        VideoMeetingResult $result,
    ): void {
        $request = $this->request($call);
        $this->assertCorrelatedRemote($result, $request);
        $this->assertRequestSchedule($result, $request);

        if (in_array($operation, [VideoMeetingOperation::Update, VideoMeetingOperation::Reconcile], true)) {
            $identity = $call->providerIdentity();
            if ($identity instanceof VideoMeetingIdentity) {
                $this->assertExpectedRemote($result, $identity, $request);
            }
        }
    }

    private function assertExpectedRemote(
        VideoMeetingResult $remote,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
    ): void {
        if (! $remote->matchesIdentity($identity)) {
            throw VideoMeetingException::reconciliationRequired('zoom_meeting_identity_mismatch');
        }
        $this->assertCorrelatedRemote($remote, $request);
    }

    private function assertCorrelatedRemote(VideoMeetingResult $remote, VideoMeetingRequest $request): void
    {
        if (! $remote->matchesCorrelation($request)) {
            throw VideoMeetingException::reconciliationRequired('zoom_meeting_correlation_mismatch');
        }
    }

    private function assertRequestSchedule(VideoMeetingResult $remote, VideoMeetingRequest $request): void
    {
        if (! $remote->matchesRequest($request)) {
            throw VideoMeetingException::reconciliationRequired('zoom_schedule_mismatch');
        }
    }

    /** @param array<string, mixed> $payload */
    private function isCurrent(B2bSalesCall $call, array $payload, VideoMeetingOperation $operation): bool
    {
        return (int) ($payload['organization_id'] ?? 0) === (int) $call->organization_id
            && (int) ($payload['sales_call_id'] ?? 0) === (int) $call->getKey()
            && (int) ($payload['event_version'] ?? 0) === (int) $call->event_version
            && (int) ($payload['provider_sync_version'] ?? 0) === (int) $call->provider_sync_version
            && (! array_key_exists('provider_correlation_key', $payload)
                || $payload['provider_correlation_key'] === $call->provider_correlation_key)
            && (! array_key_exists('provider_recreate_meeting_id', $payload)
                || $payload['provider_recreate_meeting_id'] === $call->provider_recreate_meeting_id)
            && (! array_key_exists('provider_recreate_correlation_key', $payload)
                || $payload['provider_recreate_correlation_key'] === $call->provider_recreate_correlation_key)
            && $operation === $call->provider_operation;
    }

    /** @param array<string, mixed> $payload */
    private function markReady(
        ProviderOperationLease $lease,
        array $payload,
        VideoMeetingResult $result,
    ): bool {
        return DB::transaction(function () use ($lease, $payload, $result): bool {
            $call = B2bSalesCall::query()
                ->where('organization_id', $lease->organizationId)
                ->whereKey($lease->salesCallId)
                ->lockForUpdate()
                ->firstOrFail();
            $event = IntegrationEvent::query()
                ->where('organization_id', $lease->organizationId)
                ->whereKey($lease->eventId)
                ->lockForUpdate()
                ->firstOrFail();
            $eventOwned = $event->getRawOriginal('status') === IntegrationEventStatus::Processing->value
                && $event->getRawOriginal('processing_token') === $lease->eventProcessingToken;
            $leaseActive = $this->leaseIsActive($call, $lease);
            $active = $eventOwned
                && $leaseActive
                && $this->isCurrent($call, $payload, $lease->operation)
                && $call->status === B2bSalesCallStatus::Scheduled
                && $call->meeting_mode === VideoMeetingMode::Automatic;

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
                    'provider_recreate_correlation_key' => null,
                    ...$this->clearLease(),
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

            if ($eventOwned && $leaseActive) {
                $event->forceFill([
                    'status' => IntegrationEventStatus::Processed,
                    'processed_at' => now(),
                    'processing_started_at' => null,
                    'processing_token' => null,
                    'updated_at' => now(),
                ])->save();
            }

            return $active;
        });
    }

    /** @param array<string, mixed> $payload */
    private function markCancelled(ProviderOperationLease $lease, array $payload): void
    {
        DB::transaction(function () use ($lease, $payload): void {
            $call = B2bSalesCall::query()
                ->where('organization_id', $lease->organizationId)
                ->whereKey($lease->salesCallId)
                ->lockForUpdate()
                ->firstOrFail();
            $event = IntegrationEvent::query()
                ->where('organization_id', $lease->organizationId)
                ->whereKey($lease->eventId)
                ->lockForUpdate()
                ->firstOrFail();
            $eventOwned = $event->getRawOriginal('status') === IntegrationEventStatus::Processing->value
                && $event->getRawOriginal('processing_token') === $lease->eventProcessingToken;
            $leaseActive = $this->leaseIsActive($call, $lease);
            $current = $eventOwned
                && $leaseActive
                && $this->isCurrent($call, $payload, VideoMeetingOperation::Cancel);

            if ($current && ($call->status === B2bSalesCallStatus::Cancelled || $call->meeting_mode === VideoMeetingMode::Manual)) {
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
                    'provider_recreate_correlation_key' => null,
                    'provider_correlation_key' => $call->meeting_mode === VideoMeetingMode::Manual
                        ? $call->provider_correlation_key
                        : null,
                    ...$this->clearLease(),
                ])->save();
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

            if ($eventOwned && $leaseActive) {
                $event->forceFill([
                    'status' => IntegrationEventStatus::Processed,
                    'processed_at' => now(),
                    'processing_started_at' => null,
                    'processing_token' => null,
                    'updated_at' => now(),
                ])->save();
            }
        });
    }

    /** @param array<string, mixed> $payload */
    private function markFailure(
        ProviderOperationLease $lease,
        array $payload,
        VideoMeetingException $exception,
    ): void {
        DB::transaction(function () use ($lease, $payload, $exception): void {
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
            if (! $event instanceof IntegrationEvent
                || $event->getRawOriginal('status') !== IntegrationEventStatus::Processing->value
                || $event->getRawOriginal('processing_token') !== $lease->eventProcessingToken
                || ! $call instanceof B2bSalesCall
                || ! $this->leaseIsActive($call, $lease)) {
                return;
            }

            $current = (int) $call->provider_sync_version === $lease->providerSyncVersion;
            if ($current) {
                $status = $call->provider_sync_status === VideoMeetingSyncStatus::ReconciliationRequired
                    || $exception->outcomeUnknown
                    || $exception->requiresReconciliation
                    ? VideoMeetingSyncStatus::ReconciliationRequired
                    : VideoMeetingSyncStatus::Failed;
                $call->forceFill([
                    'provider_sync_status' => $status,
                    'provider_error_code' => $exception->safeCode,
                    ...$this->clearLease(),
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

            $retry = $exception->retryable && (int) $event->attempt_count < (int) config('b2b.events.max_attempts');
            $event->forceFill([
                'status' => $retry ? IntegrationEventStatus::Retryable : IntegrationEventStatus::Failed,
                'available_at' => $retry ? now()->addSeconds((int) config('b2b.events.retry_after_seconds')) : $event->available_at,
                'processing_started_at' => null,
                'processing_token' => null,
                'updated_at' => now(),
            ])->save();
        });
    }

    private function markProcessed(ProviderOperationLease $lease): void
    {
        DB::transaction(function () use ($lease): void {
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
            if (! $event instanceof IntegrationEvent
                || $event->getRawOriginal('status') !== IntegrationEventStatus::Processing->value
                || $event->getRawOriginal('processing_token') !== $lease->eventProcessingToken
                || ! $call instanceof B2bSalesCall
                || ! $this->leaseIsActive($call, $lease)) {
                return;
            }

            $call->forceFill($this->clearLease())->save();
            $event->forceFill([
                'status' => IntegrationEventStatus::Processed,
                'processed_at' => now(),
                'processing_started_at' => null,
                'processing_token' => null,
                'updated_at' => now(),
            ])->save();
        });
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

    private function assertCurrentGeneration(ProviderOperationLease $lease): void
    {
        if (! $this->leases->owns($lease)) {
            throw new ProviderLeaseLost;
        }
    }

    private function leaseIsActive(B2bSalesCall $call, ProviderOperationLease $lease): bool
    {
        return (string) $call->provider_lease_token === $lease->leaseToken
            && (int) $call->provider_lease_event_id === $lease->eventId
            && (string) $call->provider_lease_processing_token === $lease->eventProcessingToken
            && $call->provider_lease_expires_at !== null
            && CarbonImmutable::parse((string) $call->provider_lease_expires_at)->greaterThan(CarbonImmutable::now('UTC'));
    }
}
