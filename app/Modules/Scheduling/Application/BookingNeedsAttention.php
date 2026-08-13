<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;

final class BookingNeedsAttention
{
    public function __construct(private readonly CalculateAvailability $availability) {}

    public function handle(Booking $booking): bool
    {
        if (in_array($booking->status->value, BookingStatus::terminalValues(), true)) {
            return false;
        }

        return ! $this->availability->isExistingBookingAligned($booking);
    }
}
