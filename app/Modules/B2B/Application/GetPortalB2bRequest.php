<?php

namespace App\Modules\B2B\Application;

use App\Modules\B2B\Domain\Enums\B2bSalesCallStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;

final class GetPortalB2bRequest
{
    public function __construct(private readonly OrganizationContext $context) {}

    /** @return array{leadId: int, salesCallId: int, startsAt: string, endsAt: string, requestedTimezone: string, specialistName: string, meetingMode: string, meetingStatus: string, meetingUrl: string|null}|null */
    public function handle(Client $client): ?array
    {
        abort_unless((int) $client->organization_id === $this->context->id(), 404);

        $call = B2bSalesCall::query()
            ->where('organization_id', $this->context->id())
            ->where('client_id', $client->getKey())
            ->where('status', B2bSalesCallStatus::Scheduled->value)
            ->with('specialist')
            ->latest('starts_at')
            ->latest('id')
            ->first();

        if (! $call instanceof B2bSalesCall) {
            return null;
        }

        $meetingUrl = $this->meetingUrl($call);

        return [
            'leadId' => (int) $call->lead_id,
            'salesCallId' => (int) $call->getKey(),
            'startsAt' => $call->startsAtUtc()->toIso8601String(),
            'endsAt' => $call->endsAtUtc()->toIso8601String(),
            'requestedTimezone' => (string) $call->requested_timezone,
            'specialistName' => (string) $call->specialist->display_name,
            'meetingMode' => $call->meeting_mode->value,
            'meetingStatus' => $this->meetingStatus($call, $meetingUrl),
            'meetingUrl' => $meetingUrl,
        ];
    }

    private function meetingStatus(B2bSalesCall $call, ?string $meetingUrl): string
    {
        if ($meetingUrl !== null) {
            return 'ready';
        }

        if ($call->meeting_mode === VideoMeetingMode::Manual) {
            return 'manual_pending';
        }

        return $call->provider_sync_status === VideoMeetingSyncStatus::Pending
            ? 'automatic_pending'
            : 'needs_sync';
    }

    private function meetingUrl(B2bSalesCall $call): ?string
    {
        $url = $call->meeting_mode === VideoMeetingMode::Manual
            ? $call->manual_meeting_url
            : ($call->provider_sync_status === VideoMeetingSyncStatus::Ready ? $call->provider_join_url : null);

        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || trim((string) $parts['host']) === ''
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)) {
            return null;
        }

        return $url;
    }
}
