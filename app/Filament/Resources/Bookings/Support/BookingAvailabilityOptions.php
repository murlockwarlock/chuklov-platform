<?php

namespace App\Filament\Resources\Bookings\Support;

use App\Models\User;
use App\Modules\Scheduling\Application\CalculateAvailability;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class BookingAvailabilityOptions
{
    public function __construct(
        private readonly CalculateAvailability $availability,
    ) {}

    /** @return array<string, string> */
    public function forDate(
        User $actor,
        int $specialistId,
        int $serviceId,
        VisitFormat $format,
        CarbonImmutable $date,
        string $displayTimezone,
        ?int $workingLocationId = null,
        ?string $locationArea = null,
        ?int $ignoreBookingId = null,
        bool $allowHistorical = false,
    ): array {
        $isHistoricalDate = $allowHistorical
            && $date->toDateString() <= CarbonImmutable::now($displayTimezone)->toDateString();

        try {
            $availability = $this->availability->forStaff(
                actor: $actor,
                specialistId: $specialistId,
                serviceId: $serviceId,
                dateFrom: $date->subDays(2)->toDateString(),
                dateTo: $date->addDays(2)->toDateString(),
                format: $format,
                displayTimezone: $displayTimezone,
                workingLocationId: $workingLocationId,
                locationArea: $locationArea,
                ignoreBookingId: $ignoreBookingId,
                leadTimeMinutes: $isHistoricalDate ? 0 : null,
                now: $isHistoricalDate ? $date->subSecond()->utc() : null,
            );
        } catch (InvalidArgumentException|ValidationException) {
            return [];
        }

        $options = [];
        foreach ($availability->slots as $slot) {
            $startsAt = $slot->startsAt->setTimezone($availability->displayTimezone);
            if ($startsAt->toDateString() !== $date->toDateString()) {
                continue;
            }

            $options[$slot->startsAt->utc()->toIso8601String()] = $startsAt->format('H:i')
                .'–'.$slot->endsAt->setTimezone($availability->displayTimezone)->format('H:i');
        }

        return $options;
    }
}
