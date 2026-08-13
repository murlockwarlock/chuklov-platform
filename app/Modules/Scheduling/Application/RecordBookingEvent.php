<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scheduling\Domain\Enums\BookingEventType;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;

final class RecordBookingEvent
{
    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function handle(
        Booking $booking,
        User|Client|null $actor,
        BookingEventType $type,
        array $oldValues,
        array $newValues,
        ?string $reason = null,
    ): BookingEvent {
        $event = new BookingEvent;
        $event->forceFill([
            'organization_id' => $booking->organization_id,
            'booking_id' => $booking->getKey(),
            'event_type' => $type,
            'actor_type' => $actor instanceof User ? 'user' : ($actor instanceof Client ? 'client' : 'system'),
            'actor_user_id' => $actor instanceof User ? $actor->getKey() : null,
            'actor_client_id' => $actor instanceof Client ? $actor->getKey() : null,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
        $event->save();

        return $event->refresh();
    }

    /** @return array<string, int|string|null> */
    public function snapshot(Booking $booking): array
    {
        return [
            'status' => $booking->status->value,
            'payment_status' => $booking->payment_status->value,
            'payment_requirement' => $booking->payment_requirement?->value,
            'payment_requirement_amount_minor' => $booking->payment_requirement_amount_minor,
            'payment_requirement_currency' => $booking->payment_requirement_currency,
            'visit_format' => $booking->visit_format->value,
            'starts_at' => $booking->startsAtUtc()->toIso8601String(),
            'ends_at' => $booking->endsAtUtc()->toIso8601String(),
            'blocking_ends_at' => $booking->blockingEndsAtUtc()->toIso8601String(),
            'schedule_timezone' => $booking->schedule_timezone,
            'client_timezone' => $booking->client_timezone,
            'meeting_link_mode' => $booking->meeting_link_mode?->value,
            'party_size' => $booking->party_size,
            'event_version' => $booking->event_version,
        ];
    }
}
