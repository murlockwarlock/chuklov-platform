<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\B2B\Domain\Enums\B2bSalesCallStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;

final class B2bSalesCallReadyGuard
{
    /** @param array<string, mixed>|null $renderContext */
    public function allows(
        ScenarioEvent $event,
        ?B2bSalesCall $salesCall = null,
        ?array $renderContext = null,
    ): bool {
        if ($event->event_name !== ScenarioEventType::B2bSalesCallReady) {
            return false;
        }

        $call = $salesCall ?? B2bSalesCall::query()
            ->where('organization_id', $event->organization_id)
            ->whereKey($this->positiveInt($event->payload['sales_call_id'] ?? null))
            ->first();

        if (! $call instanceof B2bSalesCall || ! $this->matchesEvent($event, $call)) {
            return false;
        }

        $joinUrl = $this->currentJoinUrl($call);
        if ($joinUrl === null) {
            return false;
        }

        if ($renderContext !== null
            && (($renderContext['sales_call']['join_url'] ?? null) !== $joinUrl)) {
            return false;
        }

        return true;
    }

    private function matchesEvent(ScenarioEvent $event, B2bSalesCall $call): bool
    {
        $payload = $event->payload;

        return (int) ($payload['organization_id'] ?? 0) === (int) $call->organization_id
            && (int) ($payload['sales_call_id'] ?? 0) === (int) $call->getKey()
            && (int) ($payload['event_version'] ?? 0) === (int) $call->event_version
            && (int) ($payload['provider_sync_version'] ?? 0) === (int) $call->provider_sync_version
            && ($payload['provider_correlation_key'] ?? null) === $call->provider_correlation_key
            && ($payload['meeting_mode'] ?? null) === $call->meeting_mode->value;
    }

    private function currentJoinUrl(B2bSalesCall $call): ?string
    {
        if ($call->status !== B2bSalesCallStatus::Scheduled) {
            return null;
        }

        $url = $call->meeting_mode === VideoMeetingMode::Manual
            ? $call->manual_meeting_url
            : ($call->provider_sync_status === VideoMeetingSyncStatus::Ready ? $call->provider_join_url : null);

        return $this->httpsUrl($url);
    }

    private function httpsUrl(mixed $url): ?string
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)) {
            return null;
        }

        return $url;
    }

    private function positiveInt(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return 0;
    }
}
