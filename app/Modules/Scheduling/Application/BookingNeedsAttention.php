<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Services\Domain\Enums\CatalogItemType;

final class BookingNeedsAttention
{
    public function __construct(private readonly CalculateAvailability $availability) {}

    public function handle(Booking $booking): bool
    {
        if (in_array($booking->status->value, BookingStatus::terminalValues(), true)) {
            return false;
        }

        $booking->loadMissing(['specialist', 'service']);

        if (! $booking->specialist->is_active
            || ! $booking->service->is_active
            || $booking->service->catalogItemType() !== CatalogItemType::Service
            || $booking->service->durationMinutes() === null
            || ! in_array($booking->visit_format->value, $booking->service->supportedFormats(), true)
            || ! SpecialistServiceAssignment::query()
                ->where('organization_id', $booking->organization_id)
                ->where('specialist_id', $booking->specialist_id)
                ->where('service_id', $booking->service_id)
                ->exists()) {
            return true;
        }

        $availability = $this->availability->forBooking(
            specialist: $booking->specialist,
            service: $booking->service,
            format: $booking->visit_format,
            startsAt: $booking->startsAtUtc(),
            displayTimezone: $booking->client_timezone,
            ignoreBookingId: $booking->getKey(),
        );

        foreach ($availability->slots as $slot) {
            if ($slot->startsAt->equalTo($booking->startsAtUtc())) {
                return false;
            }
        }

        return true;
    }
}
