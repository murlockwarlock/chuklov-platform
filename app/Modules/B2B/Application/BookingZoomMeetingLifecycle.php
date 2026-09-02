<?php

namespace App\Modules\B2B\Application;

use App\Modules\B2B\Domain\Enums\VideoMeetingOperation;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\ValueObjects\ProviderAccountAffinity;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingIdentity;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Contracts\BookingVideoMeetingLifecycle;
use App\Modules\Scheduling\Domain\Enums\MeetingLinkMode;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Illuminate\Validation\ValidationException;

final class BookingZoomMeetingLifecycle implements BookingVideoMeetingLifecycle
{
    public function __construct(
        private readonly BookingProviderMutationGuard $providerMutationGuard,
        private readonly RecordBookingProviderSyncEvent $providerEvents,
    ) {}

    public function resolveMeetingLinkMode(Organization $organization, ?MeetingLinkMode $requested): MeetingLinkMode
    {
        if ($requested === MeetingLinkMode::Manual) {
            return MeetingLinkMode::Manual;
        }

        $configured = $this->activeAffinity($organization) instanceof ProviderAccountAffinity;
        if ($requested === MeetingLinkMode::Auto && ! $configured) {
            throw ValidationException::withMessages([
                'meetingLinkMode' => 'Для автоматической онлайн-записи требуется активная полная настройка Zoom.',
            ]);
        }

        return $configured ? MeetingLinkMode::Auto : MeetingLinkMode::Manual;
    }

    public function scheduleCreate(Organization $organization, Booking $booking): void
    {
        if ($booking->visit_format->value !== 'online' || $booking->meeting_link_mode !== MeetingLinkMode::Auto) {
            return;
        }

        $affinity = $this->activeAffinity($organization);
        if (! $affinity instanceof ProviderAccountAffinity) {
            throw ValidationException::withMessages([
                'meetingLinkMode' => 'Для автоматической онлайн-записи требуется активная полная настройка Zoom.',
            ]);
        }

        $booking->forceFill([
            'provider_name' => 'zoom',
            'provider_account_id' => $affinity->accountId,
            'provider_host_user_id' => $affinity->hostUserId,
            'provider_sync_status' => VideoMeetingSyncStatus::Pending,
            'provider_operation' => VideoMeetingOperation::Create,
            'provider_sync_version' => max(1, (int) $booking->provider_sync_version),
            'provider_correlation_key' => $this->correlationKey(),
            'provider_join_url' => null,
            'provider_error_code' => null,
            ...$this->clearLease(),
        ])->save();
        $this->providerEvents->handle($organization, $booking, VideoMeetingOperation::Create);
    }

    public function scheduleReschedule(Organization $organization, Booking $booking): void
    {
        if ($booking->visit_format->value !== 'online' || $booking->meeting_link_mode !== MeetingLinkMode::Auto) {
            return;
        }

        $this->providerMutationGuard->assertAllowed($booking);
        $affinity = $booking->providerAccountAffinity();
        $correlationKey = trim((string) $booking->provider_correlation_key);
        if (! $affinity instanceof ProviderAccountAffinity || $correlationKey === '') {
            throw ValidationException::withMessages([
                'provider' => 'Текущее поколение Zoom не подтверждено и требует сверки.',
            ]);
        }

        $operation = $booking->providerIdentity() instanceof VideoMeetingIdentity
            ? VideoMeetingOperation::Update
            : VideoMeetingOperation::Create;
        if ($booking->provider_sync_status === VideoMeetingSyncStatus::ReconciliationRequired) {
            throw ValidationException::withMessages([
                'provider' => 'Текущее поколение Zoom не подтверждено и требует сверки.',
            ]);
        }

        $booking->forceFill([
            'provider_sync_status' => VideoMeetingSyncStatus::Pending,
            'provider_operation' => $operation,
            'provider_sync_version' => (int) $booking->provider_sync_version + 1,
            'provider_join_url' => null,
            'meeting_url' => null,
            'provider_error_code' => null,
            ...$this->clearLease(),
        ])->save();
        $this->providerEvents->handle($organization, $booking, $operation);
    }

    public function scheduleCancel(Organization $organization, Booking $booking): void
    {
        if ($booking->visit_format->value !== 'online' || $booking->meeting_link_mode !== MeetingLinkMode::Auto) {
            return;
        }

        $this->providerMutationGuard->assertAllowed($booking);
        $identity = $booking->providerIdentity();
        if ($identity === null) {
            $booking->forceFill([
                'provider_sync_status' => VideoMeetingSyncStatus::NotRequired,
                'provider_operation' => null,
                'provider_sync_version' => (int) $booking->provider_sync_version + 1,
                'provider_join_url' => null,
                'meeting_url' => null,
                'provider_error_code' => null,
                ...$this->clearLease(),
            ])->save();

            return;
        }

        if (trim((string) $booking->provider_correlation_key) === '') {
            throw ValidationException::withMessages([
                'provider' => 'Текущее поколение Zoom не подтверждено и требует сверки.',
            ]);
        }

        $booking->forceFill([
            'provider_sync_status' => VideoMeetingSyncStatus::CancellationPending,
            'provider_operation' => VideoMeetingOperation::Cancel,
            'provider_sync_version' => (int) $booking->provider_sync_version + 1,
            'provider_error_code' => null,
            ...$this->clearLease(),
        ])->save();
        $this->providerEvents->handle($organization, $booking, VideoMeetingOperation::Cancel);
    }

    private function activeAffinity(Organization $organization): ?ProviderAccountAffinity
    {
        $credential = OrganizationCredential::query()
            ->where('organization_id', $organization->getKey())
            ->where('provider', 'zoom')
            ->where('credential_name', (string) config('b2b.credential_name'))
            ->where('status', CredentialStatus::Active->value)
            ->first();
        $credentials = is_array($credential?->credentials) ? $credential->credentials : [];

        foreach (['account_id', 'client_id', 'client_secret', 'host_user_id'] as $key) {
            if (! is_string($credentials[$key] ?? null) || trim((string) $credentials[$key]) === '') {
                return null;
            }
        }

        return new ProviderAccountAffinity(
            accountId: trim((string) $credentials['account_id']),
            hostUserId: trim((string) $credentials['host_user_id']),
        );
    }

    private function correlationKey(): string
    {
        return bin2hex(random_bytes(16));
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
