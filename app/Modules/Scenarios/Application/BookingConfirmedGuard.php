<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;

final class BookingConfirmedGuard
{
    public function waitsForMeeting(?Booking $booking): bool
    {
        return $booking instanceof Booking
            && $booking->visit_format === VisitFormat::Online
            && $booking->meeting_link_mode?->value === 'auto'
            && $booking->effectiveMeetingUrl() === null
            && $booking->getRawOriginal('provider_sync_status') === VideoMeetingSyncStatus::Pending->value;
    }

    /** @param array<string, mixed>|null $renderContext */
    public function allows(
        ScenarioEvent $event,
        ?Booking $booking = null,
        ?array $renderContext = null,
    ): bool {
        if ($event->event_name !== ScenarioEventType::BookingConfirmed) {
            return false;
        }

        $booking ??= Booking::query()
            ->where('organization_id', $event->organization_id)
            ->whereKey($this->positiveInt($event->payload['booking_id'] ?? null))
            ->first();

        if (! $booking instanceof Booking
            || ! $this->matchesEvent($event, $booking)
            || $booking->status !== BookingStatus::Confirmed) {
            return false;
        }

        if ($booking->visit_format === VisitFormat::Online && $booking->effectiveMeetingUrl() === null) {
            return false;
        }

        if ($renderContext === null) {
            return true;
        }

        $context = $renderContext['booking'] ?? null;
        if (! is_array($context)) {
            return false;
        }

        $timezone = (string) ($booking->client_timezone ?: $booking->client->timezone ?: $booking->schedule_timezone);
        $localStart = $booking->startsAtUtc()->setTimezone($timezone);

        return (int) ($context['id'] ?? 0) === (int) $booking->getKey()
            && (int) ($context['event_version'] ?? 0) === (int) $booking->event_version
            && ($context['local_date'] ?? null) === $localStart->format('d-m-Y')
            && ($context['local_time'] ?? null) === $localStart->format('H:i')
            && ($context['timezone'] ?? null) === $timezone
            && ($context['meeting_url'] ?? null) === $booking->effectiveMeetingUrl();
    }

    private function matchesEvent(ScenarioEvent $event, Booking $booking): bool
    {
        $payload = $event->payload;

        return (int) ($payload['organization_id'] ?? 0) === (int) $booking->organization_id
            && (int) ($payload['booking_id'] ?? 0) === (int) $booking->getKey()
            && (int) ($payload['event_version'] ?? 0) === (int) $booking->event_version
            && ($payload['status'] ?? null) === $booking->status->value
            && ($payload['visit_format'] ?? null) === $booking->visit_format->value
            && ($payload['starts_at'] ?? null) === $booking->startsAtUtc()->toIso8601String()
            && ($payload['ends_at'] ?? null) === $booking->endsAtUtc()->toIso8601String();
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
