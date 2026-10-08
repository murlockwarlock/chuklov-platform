<?php

namespace App\Filament\Resources\Bookings\Support;

use App\Models\User;
use App\Modules\Scheduling\Application\AvailabilityResult;
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
        $actualNow = CarbonImmutable::now($displayTimezone);
        $actualNowUtc = $actualNow->utc();
        $dateKey = $date->toDateString();
        $todayKey = $actualNow->toDateString();
        $isHistoricalDate = $allowHistorical && $dateKey < $todayKey;
        $isTodayWithHistoricalAccess = $allowHistorical && $dateKey === $todayKey;

        try {
            $calculate = function (?int $leadTimeMinutes, ?CarbonImmutable $now) use (
                $actor,
                $specialistId,
                $serviceId,
                $date,
                $format,
                $displayTimezone,
                $workingLocationId,
                $locationArea,
                $ignoreBookingId,
            ): AvailabilityResult {
                return $this->availability->forStaff(
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
                    leadTimeMinutes: $leadTimeMinutes,
                    now: $now,
                );
            };

            if ($isTodayWithHistoricalAccess) {
                $historicalAvailability = $calculate(0, $date->subSecond()->utc());
                $normalAvailability = $calculate(null, $actualNowUtc);
                $availability = $historicalAvailability;
                $slots = [
                    ...array_filter(
                        $historicalAvailability->slots,
                        static fn ($slot): bool => $slot->startsAt->lessThan($actualNowUtc),
                    ),
                    ...array_filter(
                        $normalAvailability->slots,
                        static fn ($slot): bool => $slot->startsAt->greaterThanOrEqualTo($actualNowUtc),
                    ),
                ];
            } else {
                $availability = $calculate(
                    $isHistoricalDate ? 0 : null,
                    $isHistoricalDate ? $date->subSecond()->utc() : null,
                );
                $slots = $availability->slots;
            }
        } catch (InvalidArgumentException|ValidationException) {
            return [];
        }

        $options = [];
        foreach ($slots as $slot) {
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
