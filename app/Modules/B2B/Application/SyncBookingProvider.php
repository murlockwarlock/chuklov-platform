<?php

namespace App\Modules\B2B\Application;

use App\Modules\B2B\Domain\Contracts\VideoMeetingProvider;
use App\Modules\B2B\Domain\Enums\VideoMeetingOperation;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\ValueObjects\BookingProviderOperationLease;
use App\Modules\B2B\Domain\ValueObjects\ProviderAccountAffinity;
use App\Modules\B2B\Domain\ValueObjects\ProviderOperationDeadline;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingIdentity;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingRequest;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingResult;
use App\Modules\B2B\Infrastructure\Video\VideoMeetingException;
use App\Modules\Integration\Domain\Enums\IntegrationEventStatus;
use App\Modules\Integration\Domain\Models\IntegrationEvent;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SyncBookingProvider
{
    public function __construct(
        private readonly VideoMeetingProvider $provider,
        private readonly BookingProviderLeaseManager $leases,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(int $eventId): void
    {
        $lease = $this->leases->claim($eventId);
        if (! $lease instanceof BookingProviderOperationLease) {
            return;
        }

        $event = $this->event($lease);
        $payload = $event->payload;
        $booking = $this->booking($lease);
        $organization = Organization::query()->findOrFail($lease->organizationId);
        $deadline = $lease->providerDeadline();

        if (! $this->isCurrentEnvelope($booking, $payload, $lease->operation)
            || ($booking->status->value === 'cancelled' && $lease->operation !== VideoMeetingOperation::Cancel)) {
            $this->markProcessed($lease);

            return;
        }

        if (! $this->hasProviderAffinityEvidence($payload)) {
            $this->markFailure($lease, $payload, VideoMeetingException::reconciliationRequired('zoom_provider_affinity_missing'));

            return;
        }

        if (! $this->isCurrent($booking, $payload, $lease->operation)) {
            $this->markProcessed($lease);

            return;
        }

        try {
            $result = $this->perform($organization, $booking, $lease, $deadline);
            if ($result instanceof VideoMeetingResult) {
                $this->assertResult($booking, $result);
                $this->markReady($lease, $payload, $result);

                return;
            }

            $this->markCancelled($lease, $payload);
        } catch (ProviderLeaseLost) {
        } catch (VideoMeetingException $exception) {
            $this->markFailure($lease, $payload, $exception);
        } catch (Throwable) {
            $this->markFailure($lease, $payload, VideoMeetingException::retryable('provider_unexpected', true));
        }
    }

    private function perform(
        Organization $organization,
        Booking $booking,
        BookingProviderOperationLease $lease,
        ProviderOperationDeadline $deadline,
    ): ?VideoMeetingResult {
        return match ($lease->operation) {
            VideoMeetingOperation::Create => $this->create($organization, $booking, $lease, $deadline),
            VideoMeetingOperation::Update => $this->update($organization, $booking, $lease, $deadline),
            VideoMeetingOperation::Cancel => $this->cancel($organization, $booking, $lease, $deadline),
            VideoMeetingOperation::Reconcile => $this->reconcile($organization, $booking, $lease, $deadline),
            VideoMeetingOperation::Recreate => throw VideoMeetingException::reconciliationRequired('booking_provider_operation_invalid'),
        };
    }

    private function create(
        Organization $organization,
        Booking $booking,
        BookingProviderOperationLease $lease,
        ProviderOperationDeadline $deadline,
    ): VideoMeetingResult {
        if ($booking->providerIdentity() instanceof VideoMeetingIdentity) {
            throw VideoMeetingException::reconciliationRequired('zoom_create_known_identity');
        }

        $request = $this->request($booking);
        $existing = $this->provider->findMeeting($organization, $request, $deadline);
        if ($existing instanceof VideoMeetingResult) {
            if (! $existing->matchesRequest($request)) {
                $this->assertCurrentGeneration($lease);
                $this->provider->updateMeeting($organization, $existing->identity, $request, $deadline);

                return $this->readAndVerify($organization, $existing->identity, $request, $lease, $deadline);
            }

            return $existing;
        }

        if ($booking->provider_sync_status === VideoMeetingSyncStatus::ReconciliationRequired) {
            throw VideoMeetingException::reconciliationRequired('zoom_create_unresolved');
        }

        $this->assertCurrentGeneration($lease);

        return $this->provider->createMeeting($organization, $request, $deadline);
    }

    private function update(
        Organization $organization,
        Booking $booking,
        BookingProviderOperationLease $lease,
        ProviderOperationDeadline $deadline,
    ): VideoMeetingResult {
        $identity = $booking->providerIdentity();
        if (! $identity instanceof VideoMeetingIdentity) {
            throw VideoMeetingException::reconciliationRequired('zoom_update_identity_missing');
        }

        $request = $this->request($booking);
        $this->assertCurrentGeneration($lease);
        $remote = $this->provider->getMeeting($organization, $identity, $request, $deadline);
        if (! $remote instanceof VideoMeetingResult) {
            throw VideoMeetingException::reconciliationRequired('zoom_update_identity_missing');
        }
        if (! $remote->matchesIdentity($identity) || ! $remote->matchesCorrelation($request)) {
            throw VideoMeetingException::reconciliationRequired('zoom_meeting_identity_mismatch');
        }

        $this->assertCurrentGeneration($lease);
        $this->provider->updateMeeting($organization, $identity, $request, $deadline);

        return $this->readAndVerify($organization, $identity, $request, $lease, $deadline);
    }

    private function cancel(
        Organization $organization,
        Booking $booking,
        BookingProviderOperationLease $lease,
        ProviderOperationDeadline $deadline,
    ): null {
        $identity = $booking->providerIdentity();
        if (! $identity instanceof VideoMeetingIdentity) {
            return null;
        }

        $this->assertCurrentGeneration($lease);
        $this->provider->cancelMeeting($organization, $identity, $this->request($booking), $deadline);

        return null;
    }

    private function reconcile(
        Organization $organization,
        Booking $booking,
        BookingProviderOperationLease $lease,
        ProviderOperationDeadline $deadline,
    ): VideoMeetingResult {
        $request = $this->request($booking);
        $identity = $booking->providerIdentity();
        if ($identity instanceof VideoMeetingIdentity) {
            $result = $this->provider->getMeeting($organization, $identity, $request, $deadline);
            if (! $result instanceof VideoMeetingResult) {
                throw VideoMeetingException::reconciliationRequired('zoom_identity_missing');
            }

            return $this->readAndVerify($organization, $identity, $request, $lease, $deadline, $result);
        }

        $result = $this->provider->findMeeting($organization, $request, $deadline);
        if (! $result instanceof VideoMeetingResult) {
            throw VideoMeetingException::reconciliationRequired('zoom_correlation_unresolved');
        }

        return $result;
    }

    private function readAndVerify(
        Organization $organization,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
        BookingProviderOperationLease $lease,
        ProviderOperationDeadline $deadline,
        ?VideoMeetingResult $result = null,
    ): VideoMeetingResult {
        $this->assertCurrentGeneration($lease);
        $result ??= $this->provider->getMeeting($organization, $identity, $request, $deadline);
        if (! $result instanceof VideoMeetingResult
            || ! $result->matchesIdentity($identity)
            || ! $result->matchesRequest($request)) {
            throw VideoMeetingException::reconciliationRequired('zoom_schedule_mismatch');
        }

        return $result;
    }

    private function request(Booking $booking): VideoMeetingRequest
    {
        $affinity = $booking->providerAccountAffinity();
        $correlationKey = trim((string) $booking->provider_correlation_key);
        if (! $affinity instanceof ProviderAccountAffinity || $correlationKey === '') {
            throw VideoMeetingException::reconciliationRequired('zoom_provider_affinity_missing');
        }

        $duration = (int) $booking->startsAtUtc()->diffInMinutes($booking->endsAtUtc());
        if ($duration < 1) {
            throw VideoMeetingException::reconciliationRequired('booking_interval_invalid');
        }

        $booking->loadMissing('service');

        return new VideoMeetingRequest(
            externalKey: $correlationKey,
            startsAt: $booking->startsAtUtc(),
            durationMinutes: $duration,
            timezone: (string) $booking->schedule_timezone,
            topic: 'Chuklov appointment: '.trim((string) $booking->service->name),
            providerAccountAffinity: $affinity,
            correlationPrefix: 'CHUKLOV-BOOKING',
        );
    }

    private function assertResult(Booking $booking, VideoMeetingResult $result): void
    {
        $affinity = $booking->providerAccountAffinity();
        if (! $affinity instanceof ProviderAccountAffinity
            || ! $result->identity->providerAccountAffinity instanceof ProviderAccountAffinity
            || ! $result->identity->providerAccountAffinity->equals($affinity)) {
            throw VideoMeetingException::reconciliationRequired('zoom_provider_affinity_mismatch');
        }

        if (! $result->matchesRequest($this->request($booking))) {
            throw VideoMeetingException::reconciliationRequired('zoom_schedule_mismatch');
        }
    }

    /** @param array<string, mixed> $payload */
    private function isCurrentEnvelope(Booking $booking, array $payload, VideoMeetingOperation $operation): bool
    {
        return (int) ($payload['organization_id'] ?? 0) === (int) $booking->organization_id
            && (int) ($payload['booking_id'] ?? 0) === (int) $booking->getKey()
            && (int) ($payload['event_version'] ?? 0) === (int) $booking->event_version
            && (int) ($payload['provider_sync_version'] ?? 0) === (int) $booking->provider_sync_version
            && $operation === $booking->provider_operation;
    }

    /** @param array<string, mixed> $payload */
    private function isCurrent(Booking $booking, array $payload, VideoMeetingOperation $operation): bool
    {
        return $this->isCurrentEnvelope($booking, $payload, $operation)
            && array_key_exists('provider_account_id', $payload)
            && $payload['provider_account_id'] === $booking->provider_account_id
            && array_key_exists('provider_host_user_id', $payload)
            && $payload['provider_host_user_id'] === $booking->provider_host_user_id
            && array_key_exists('provider_meeting_id', $payload)
            && $payload['provider_meeting_id'] === $booking->provider_meeting_id
            && array_key_exists('provider_meeting_uuid', $payload)
            && $payload['provider_meeting_uuid'] === $booking->provider_meeting_uuid
            && array_key_exists('provider_correlation_key', $payload)
            && $payload['provider_correlation_key'] === $booking->provider_correlation_key;
    }

    /** @param array<string, mixed> $payload */
    private function hasProviderAffinityEvidence(array $payload): bool
    {
        return is_string($payload['provider_account_id'] ?? null)
            && trim((string) $payload['provider_account_id']) !== ''
            && is_string($payload['provider_host_user_id'] ?? null)
            && trim((string) $payload['provider_host_user_id']) !== '';
    }

    /** @param array<string, mixed> $payload */
    private function markReady(
        BookingProviderOperationLease $lease,
        array $payload,
        VideoMeetingResult $result,
    ): void {
        DB::transaction(function () use ($lease, $payload, $result): void {
            $booking = $this->lockedBooking($lease);
            $event = $this->lockedEvent($lease);
            $owned = $this->owned($booking, $event, $lease);
            $leaseActive = $this->leaseIsActive($booking, $lease);
            $active = $owned
                && $leaseActive
                && $this->isCurrent($booking, $payload, $lease->operation)
                && $booking->status->value !== 'cancelled'
                && $booking->visit_format->value === 'online'
                && $booking->meeting_link_mode?->value === 'auto';

            if ($active) {
                $booking->forceFill([
                    'provider_name' => $this->provider->name(),
                    'provider_meeting_id' => $result->identity->meetingId,
                    'provider_meeting_uuid' => $result->identity->meetingUuid,
                    'provider_join_url' => $result->joinUrl,
                    'meeting_url' => $result->joinUrl,
                    'provider_sync_status' => VideoMeetingSyncStatus::Ready,
                    'provider_operation' => null,
                    'provider_synced_at' => $result->synchronizedAt,
                    'provider_error_code' => null,
                    ...$this->clearLease(),
                ])->save();
                $this->audit->handle(
                    organization: $booking->organization,
                    actor: null,
                    action: 'booking.provider_sync.updated',
                    targetType: Booking::class,
                    targetId: (string) $booking->getKey(),
                    metadata: [
                        'operation' => $lease->operation->value,
                        'status' => VideoMeetingSyncStatus::Ready->value,
                        'provider' => $this->provider->name(),
                        'error_code' => null,
                    ],
                );
            }

            if ($owned && $leaseActive) {
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
    private function markCancelled(BookingProviderOperationLease $lease, array $payload): void
    {
        DB::transaction(function () use ($lease, $payload): void {
            $booking = $this->lockedBooking($lease);
            $event = $this->lockedEvent($lease);
            $owned = $this->owned($booking, $event, $lease);
            $leaseActive = $this->leaseIsActive($booking, $lease);
            if ($owned && $leaseActive && $this->isCurrent($booking, $payload, VideoMeetingOperation::Cancel)) {
                $booking->forceFill([
                    'provider_name' => null,
                    'provider_account_id' => null,
                    'provider_host_user_id' => null,
                    'provider_meeting_id' => null,
                    'provider_meeting_uuid' => null,
                    'provider_join_url' => null,
                    'meeting_url' => null,
                    'provider_sync_status' => VideoMeetingSyncStatus::NotRequired,
                    'provider_operation' => null,
                    'provider_synced_at' => now(),
                    'provider_error_code' => null,
                    'provider_correlation_key' => null,
                    ...$this->clearLease(),
                ])->save();
            }

            if ($owned && $leaseActive) {
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
        BookingProviderOperationLease $lease,
        array $payload,
        VideoMeetingException $exception,
    ): void {
        DB::transaction(function () use ($lease, $payload, $exception): void {
            $booking = $this->lockedBooking($lease);
            $event = $this->lockedEvent($lease);
            $leaseActive = $this->leaseIsActive($booking, $lease);
            if (! $this->owned($booking, $event, $lease) || ! $leaseActive) {
                return;
            }

            if ((int) $booking->provider_sync_version === $lease->providerSyncVersion) {
                $status = $exception->outcomeUnknown || $exception->requiresReconciliation
                    ? VideoMeetingSyncStatus::ReconciliationRequired
                    : VideoMeetingSyncStatus::Failed;
                $booking->forceFill([
                    'provider_sync_status' => $status,
                    'provider_error_code' => $exception->safeCode,
                    'provider_join_url' => null,
                    'meeting_url' => null,
                    ...$this->clearLease(),
                ])->save();
                $this->audit->handle(
                    organization: $booking->organization,
                    actor: null,
                    action: 'booking.provider_sync.updated',
                    targetType: Booking::class,
                    targetId: (string) $booking->getKey(),
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

    private function markProcessed(BookingProviderOperationLease $lease): void
    {
        DB::transaction(function () use ($lease): void {
            $booking = $this->lockedBooking($lease);
            $event = $this->lockedEvent($lease);
            if (! $this->owned($booking, $event, $lease) || ! $this->leaseIsActive($booking, $lease)) {
                return;
            }

            $booking->forceFill($this->clearLease())->save();
            $event->forceFill([
                'status' => IntegrationEventStatus::Processed,
                'processed_at' => now(),
                'processing_started_at' => null,
                'processing_token' => null,
                'updated_at' => now(),
            ])->save();
        });
    }

    private function event(BookingProviderOperationLease $lease): IntegrationEvent
    {
        return IntegrationEvent::query()
            ->where('organization_id', $lease->organizationId)
            ->whereKey($lease->eventId)
            ->firstOrFail();
    }

    private function booking(BookingProviderOperationLease $lease): Booking
    {
        return Booking::query()
            ->where('organization_id', $lease->organizationId)
            ->whereKey($lease->bookingId)
            ->firstOrFail();
    }

    private function lockedBooking(BookingProviderOperationLease $lease): Booking
    {
        return Booking::query()
            ->where('organization_id', $lease->organizationId)
            ->whereKey($lease->bookingId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockedEvent(BookingProviderOperationLease $lease): IntegrationEvent
    {
        return IntegrationEvent::query()
            ->where('organization_id', $lease->organizationId)
            ->whereKey($lease->eventId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function owned(
        Booking $booking,
        IntegrationEvent $event,
        BookingProviderOperationLease $lease,
    ): bool {
        return $event->getRawOriginal('status') === IntegrationEventStatus::Processing->value
            && $event->getRawOriginal('processing_token') === $lease->eventProcessingToken
            && (string) $booking->provider_lease_token === $lease->leaseToken
            && (int) $booking->provider_lease_event_id === $lease->eventId
            && (string) $booking->provider_lease_processing_token === $lease->eventProcessingToken;
    }

    private function leaseIsActive(Booking $booking, BookingProviderOperationLease $lease): bool
    {
        return (string) $booking->provider_lease_token === $lease->leaseToken
            && $booking->provider_lease_expires_at !== null
            && CarbonImmutable::parse((string) $booking->provider_lease_expires_at)->greaterThan(CarbonImmutable::now('UTC'));
    }

    private function assertCurrentGeneration(BookingProviderOperationLease $lease): void
    {
        if (! $this->leases->owns($lease)) {
            throw new ProviderLeaseLost;
        }
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
