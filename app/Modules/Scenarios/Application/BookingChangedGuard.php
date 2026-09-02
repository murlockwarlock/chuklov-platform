<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scheduling\Domain\Models\Booking;

final class BookingChangedGuard
{
    /** @param array<string, mixed>|null $renderContext */
    public function allows(
        ScenarioEvent $event,
        ?Booking $booking = null,
        ?array $renderContext = null,
    ): bool {
        if (! in_array($event->event_name, [ScenarioEventType::BookingRescheduled, ScenarioEventType::BookingCancelled], true)) {
            return false;
        }

        $booking ??= Booking::query()
            ->where('organization_id', $event->organization_id)
            ->whereKey($this->positiveInt($event->payload['booking_id'] ?? null))
            ->with('client')
            ->first();

        if (! $booking instanceof Booking || ! $this->matchesEvent($event, $booking)) {
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
            && ($context['status'] ?? null) === $booking->status->value
            && ($context['local_date'] ?? null) === $localStart->format('d-m-Y')
            && ($context['local_time'] ?? null) === $localStart->format('H:i')
            && ($context['timezone'] ?? null) === $timezone
            && ($context['meeting_url'] ?? null) === ($booking->visit_format->value === 'online' ? $booking->meeting_url : null);
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
