<?php

namespace App\Modules\B2B\Application;

use App\Models\User;
use App\Modules\B2B\Domain\Contracts\VideoMeetingProvider;
use App\Modules\B2B\Domain\Enums\B2bSalesCallStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Enums\VideoMeetingOperation;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\B2B\Domain\ValueObjects\B2bSalesCallDuration;
use App\Modules\B2B\Domain\ValueObjects\ProviderAccountAffinity;
use App\Modules\B2B\Domain\ValueObjects\ProviderOperationDeadline;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingIdentity;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingRequest;
use App\Modules\B2B\Infrastructure\Video\VideoMeetingException;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class GetB2bSalesCallHostLaunchUrl
{
    private const UNAVAILABLE_MESSAGE = 'The Zoom host link is unavailable because the meeting is not in a current ready state. Refresh and retry from the CRM.';

    private const STALE_MESSAGE = 'The Zoom host link became stale before launch. Refresh and retry from the CRM.';

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly MarkB2bSalesCallProviderReconciliationRequired $reconciliation,
        private readonly VideoMeetingProvider $provider,
    ) {}

    public function handle(User $actor, B2bSalesCall $salesCall): string
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageB2bLeads);

        if ((int) $salesCall->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The sales call is outside the current organization.');
        }

        $snapshot = $this->snapshot($organization->getKey(), (int) $salesCall->getKey());
        $identity = $this->launchIdentity($snapshot);
        $request = $this->launchRequest($snapshot);

        if ($this->provider->name() !== 'zoom') {
            throw ValidationException::withMessages([
                'provider' => 'The Zoom host link is invalid. Retry from the CRM.',
            ]);
        }

        try {
            $hostUrl = $this->provider->obtainHostLaunchUrl(
                $organization,
                $identity,
                $request,
                ProviderOperationDeadline::fromNow((int) config('b2b.provider.operation_deadline_seconds', 90)),
            );

            if (! $this->isAllowedZoomHostUrl($hostUrl)) {
                throw ValidationException::withMessages([
                    'provider' => 'The Zoom host link is invalid. Retry from the CRM.',
                ]);
            }

            if (! $this->isCurrentReadySnapshot($organization->getKey(), (int) $salesCall->getKey(), $snapshot)) {
                throw ValidationException::withMessages(['provider' => self::STALE_MESSAGE]);
            }

            return $hostUrl;
        } catch (VideoMeetingException $exception) {
            if (in_array($exception->safeCode, [
                'zoom_host_url_404',
                'zoom_meeting_identity_mismatch',
                'zoom_meeting_correlation_mismatch',
                'zoom_schedule_mismatch',
                'zoom_provider_affinity_missing',
                'zoom_provider_affinity_mismatch',
                'zoom_credentials_missing',
                'zoom_credentials_invalid',
            ], true)) {
                $this->reconciliation->handle(
                    actor: $actor,
                    salesCall: $salesCall,
                    identity: $identity,
                    errorCode: $exception->safeCode,
                    expectedEventVersion: (int) $snapshot['event_version'],
                    expectedProviderSyncVersion: (int) $snapshot['provider_sync_version'],
                );

                throw ValidationException::withMessages([
                    'provider' => 'The Zoom meeting is no longer available. Reconcile or recreate it before launching.',
                ]);
            }

            throw ValidationException::withMessages([
                'provider' => 'The Zoom host link is temporarily unavailable. Retry from the CRM.',
            ]);
        }
    }

    private function isAllowedZoomHostUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('port', $parts)) {
            return false;
        }

        $host = strtolower((string) $parts['host']);

        return $host === 'zoom.us' || preg_match('/^[a-z0-9-]+\.zoom\.us$/', $host) === 1;
    }

    /** @return array{sales_call_id: int, provider_meeting_id: string|null, provider_meeting_uuid: string|null, provider_account_id: string|null, provider_host_user_id: string|null, provider_correlation_key: string|null, starts_at: CarbonImmutable, ends_at: CarbonImmutable, schedule_timezone: string, event_version: int, provider_sync_version: int, status: B2bSalesCallStatus, meeting_mode: VideoMeetingMode, provider_sync_status: VideoMeetingSyncStatus, provider_operation: VideoMeetingOperation|null, provider_name: string|null} */
    private function snapshot(int $organizationId, int $salesCallId): array
    {
        return DB::transaction(function () use ($organizationId, $salesCallId): array {
            $salesCall = B2bSalesCall::query()
                ->where('organization_id', $organizationId)
                ->whereKey($salesCallId)
                ->lockForUpdate()
                ->firstOrFail();
            $identity = $salesCall->providerIdentity();

            return [
                'sales_call_id' => (int) $salesCall->getKey(),
                'provider_meeting_id' => $identity?->meetingId,
                'provider_meeting_uuid' => $identity?->meetingUuid,
                'provider_account_id' => $salesCall->provider_account_id,
                'provider_host_user_id' => $salesCall->provider_host_user_id,
                'provider_correlation_key' => $salesCall->provider_correlation_key,
                'starts_at' => $salesCall->startsAtUtc(),
                'ends_at' => $salesCall->endsAtUtc(),
                'schedule_timezone' => (string) $salesCall->schedule_timezone,
                'event_version' => (int) $salesCall->event_version,
                'provider_sync_version' => (int) $salesCall->provider_sync_version,
                'status' => $salesCall->status,
                'meeting_mode' => $salesCall->meeting_mode,
                'provider_sync_status' => $salesCall->provider_sync_status,
                'provider_operation' => $salesCall->provider_operation,
                'provider_name' => $salesCall->provider_name,
            ];
        });
    }

    /** @param array{sales_call_id: int, provider_meeting_id: string|null, provider_meeting_uuid: string|null, provider_account_id: string|null, provider_host_user_id: string|null, provider_correlation_key: string|null, starts_at: CarbonImmutable, ends_at: CarbonImmutable, schedule_timezone: string, event_version: int, provider_sync_version: int, status: B2bSalesCallStatus, meeting_mode: VideoMeetingMode, provider_sync_status: VideoMeetingSyncStatus, provider_operation: VideoMeetingOperation|null, provider_name: string|null} $snapshot */
    private function launchIdentity(array $snapshot): VideoMeetingIdentity
    {
        if ($snapshot['status'] !== B2bSalesCallStatus::Scheduled
            || $snapshot['meeting_mode'] !== VideoMeetingMode::Automatic
            || $snapshot['provider_sync_status'] !== VideoMeetingSyncStatus::Ready
            || $snapshot['provider_operation'] !== null
            || $snapshot['provider_name'] !== 'zoom'
            || ! is_string($snapshot['provider_correlation_key'])
            || trim($snapshot['provider_correlation_key']) === ''
            || ! is_string($snapshot['provider_meeting_id'])
            || trim($snapshot['provider_meeting_id']) === '') {
            throw ValidationException::withMessages(['provider' => self::UNAVAILABLE_MESSAGE]);
        }

        return new VideoMeetingIdentity(
            meetingId: $snapshot['provider_meeting_id'],
            meetingUuid: is_string($snapshot['provider_meeting_uuid']) && trim($snapshot['provider_meeting_uuid']) !== ''
                ? $snapshot['provider_meeting_uuid']
                : null,
            providerAccountAffinity: $this->providerAccountAffinity($snapshot),
        );
    }

    /** @param array{sales_call_id: int, provider_meeting_id: string|null, provider_meeting_uuid: string|null, provider_account_id: string|null, provider_host_user_id: string|null, provider_correlation_key: string|null, starts_at: CarbonImmutable, ends_at: CarbonImmutable, schedule_timezone: string, event_version: int, provider_sync_version: int, status: B2bSalesCallStatus, meeting_mode: VideoMeetingMode, provider_sync_status: VideoMeetingSyncStatus, provider_operation: VideoMeetingOperation|null, provider_name: string|null} $snapshot */
    private function launchRequest(array $snapshot): VideoMeetingRequest
    {
        if (! is_string($snapshot['provider_correlation_key'])
            || trim($snapshot['provider_correlation_key']) === '') {
            throw ValidationException::withMessages(['provider' => self::UNAVAILABLE_MESSAGE]);
        }

        try {
            $durationMinutes = B2bSalesCallDuration::between(
                $snapshot['starts_at'],
                $snapshot['ends_at'],
            )->minutes;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['provider' => self::UNAVAILABLE_MESSAGE]);
        }

        return new VideoMeetingRequest(
            externalKey: $snapshot['provider_correlation_key'],
            startsAt: $snapshot['starts_at'],
            durationMinutes: $durationMinutes,
            timezone: $snapshot['schedule_timezone'],
            topic: (string) config('b2b.zoom.topic'),
        );
    }

    /** @param array{sales_call_id: int, provider_meeting_id: string|null, provider_meeting_uuid: string|null, provider_account_id: string|null, provider_host_user_id: string|null, provider_correlation_key: string|null, starts_at: CarbonImmutable, ends_at: CarbonImmutable, schedule_timezone: string, event_version: int, provider_sync_version: int, status: B2bSalesCallStatus, meeting_mode: VideoMeetingMode, provider_sync_status: VideoMeetingSyncStatus, provider_operation: VideoMeetingOperation|null, provider_name: string|null} $snapshot */
    private function isCurrentReadySnapshot(int $organizationId, int $salesCallId, array $snapshot): bool
    {
        return DB::transaction(function () use ($organizationId, $salesCallId, $snapshot): bool {
            $salesCall = B2bSalesCall::query()
                ->where('organization_id', $organizationId)
                ->whereKey($salesCallId)
                ->lockForUpdate()
                ->first();

            if (! $salesCall instanceof B2bSalesCall) {
                return false;
            }

            $identity = $salesCall->providerIdentity();

            return (int) $salesCall->getKey() === (int) $snapshot['sales_call_id']
                && (int) $salesCall->event_version === (int) $snapshot['event_version']
                && (int) $salesCall->provider_sync_version === (int) $snapshot['provider_sync_version']
                && $salesCall->status === B2bSalesCallStatus::Scheduled
                && $salesCall->meeting_mode === VideoMeetingMode::Automatic
                && $salesCall->provider_sync_status === VideoMeetingSyncStatus::Ready
                && $salesCall->provider_operation === null
                && $salesCall->provider_name === 'zoom'
                && is_string($salesCall->provider_correlation_key)
                && trim($salesCall->provider_correlation_key) !== ''
                && $identity instanceof VideoMeetingIdentity
                && $identity->meetingId === $snapshot['provider_meeting_id']
                && $identity->meetingUuid === $snapshot['provider_meeting_uuid']
                && $salesCall->provider_account_id === $snapshot['provider_account_id']
                && $salesCall->provider_host_user_id === $snapshot['provider_host_user_id']
                && $salesCall->provider_correlation_key === $snapshot['provider_correlation_key'];
        });
    }

    /** @param array{provider_account_id: string|null, provider_host_user_id: string|null} $snapshot */
    private function providerAccountAffinity(array $snapshot): ?ProviderAccountAffinity
    {
        if (! is_string($snapshot['provider_account_id'])
            || trim($snapshot['provider_account_id']) === ''
            || ! is_string($snapshot['provider_host_user_id'])
            || trim($snapshot['provider_host_user_id']) === '') {
            return null;
        }

        return new ProviderAccountAffinity(
            accountId: trim($snapshot['provider_account_id']),
            hostUserId: trim($snapshot['provider_host_user_id']),
        );
    }
}
