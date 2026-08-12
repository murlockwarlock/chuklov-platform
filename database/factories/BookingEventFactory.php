<?php

namespace Database\Factories;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Enums\BookingEventType;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BookingEvent> */
class BookingEventFactory extends Factory
{
    protected $model = BookingEvent::class;

    public function definition(): array
    {
        return [
            'event_type' => BookingEventType::Created->value,
            'actor_type' => 'system',
            'old_values' => [],
            'new_values' => [],
            'reason' => null,
            'occurred_at' => now(),
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (BookingEvent $event): BookingEvent => $event->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function forBooking(Booking $booking): static
    {
        return $this->afterMaking(fn (BookingEvent $event): BookingEvent => $event->forceFill([
            'organization_id' => $booking->organization_id,
            'booking_id' => $booking->getKey(),
        ]));
    }
}
