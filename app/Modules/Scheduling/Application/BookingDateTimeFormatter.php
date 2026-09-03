<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Scheduling\Domain\Models\Booking;
use Carbon\CarbonImmutable;

final readonly class BookingDateTimeFormatter
{
    public function __construct(private ResolveSpecialistViewerTimezone $viewerTimezone) {}

    /** @return array{date: string, time: string, timezone: string} */
    public function forClient(Booking $booking): array
    {
        $timezone = $booking->client_timezone
            ?: $booking->client->timezone
            ?: $booking->schedule_timezone;

        return $this->format($booking, IanaTimezone::from($timezone)->value);
    }

    /** @return array{date: string, time: string, timezone: string} */
    public function forSpecialist(Booking $booking): array
    {
        $timezone = $this->viewerTimezone->forBooking($booking);

        return $this->format($booking, $timezone);
    }

    /** @return array{date: string, time: string, timezone: string} */
    public function format(Booking $booking, string $timezone): array
    {
        $localStart = CarbonImmutable::instance($booking->startsAtUtc())->setTimezone($timezone);

        return [
            'date' => $localStart->format('d-m-Y'),
            'time' => $localStart->format('H:i'),
            'timezone' => $timezone,
        ];
    }
}
