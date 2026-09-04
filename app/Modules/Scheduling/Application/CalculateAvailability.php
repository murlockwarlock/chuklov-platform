<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\LocationDay;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use App\Modules\Scheduling\Domain\Models\SpecialistWorkingHour;
use App\Modules\Scheduling\Domain\Models\UnavailablePeriod;
use App\Modules\Scheduling\Domain\Models\WorkingLocation;
use App\Modules\Scheduling\Domain\Services\SlotCalculator;
use App\Modules\Scheduling\Domain\ValueObjects\AvailabilitySlot;
use App\Modules\Scheduling\Domain\ValueObjects\InstantInterval;
use App\Modules\Scheduling\Domain\ValueObjects\LocalDate;
use App\Modules\Scheduling\Domain\ValueObjects\WallClockInterval;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CalculateAvailability
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationFeatureGate $features,
        private readonly GetBookingLeadTime $leadTime,
        private readonly SlotCalculator $calculator,
        private readonly SpecialistServiceAssignmentEligibility $eligibility,
        private readonly BookingLocationResolver $locations,
        private readonly GetHomeVisitOccupiedBuffer $homeVisitBuffer,
    ) {}

    public function forStaff(
        User $actor,
        int $specialistId,
        int $serviceId,
        string $dateFrom,
        string $dateTo,
        VisitFormat $format,
        ?string $displayTimezone = null,
        ?int $workingLocationId = null,
        ?string $locationArea = null,
    ): AvailabilityResult {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewScheduling);
        $this->features->authorize($organization, OrganizationFeature::ServiceCatalog);

        return $this->calculateForIds(
            specialistId: $specialistId,
            serviceId: $serviceId,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            format: $format,
            displayTimezone: $displayTimezone,
            workingLocationId: $workingLocationId,
            locationArea: $locationArea,
        );
    }

    public function forClient(
        Client $client,
        int $specialistId,
        int $serviceId,
        string $dateFrom,
        string $dateTo,
        VisitFormat $format,
        ?string $displayTimezone = null,
        ?int $workingLocationId = null,
        ?string $locationArea = null,
    ): AvailabilityResult {
        $organization = $this->context->organization();

        if ((int) $client->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }
        $this->features->authorize($organization, OrganizationFeature::ServiceCatalog);

        return $this->calculateForIds(
            specialistId: $specialistId,
            serviceId: $serviceId,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            format: $format,
            displayTimezone: $displayTimezone,
            client: $client,
            datesInDisplayTimezone: true,
            workingLocationId: $workingLocationId,
            locationArea: $locationArea,
        );
    }

    public function forB2b(
        Client $client,
        Specialist $specialist,
        string $dateFrom,
        string $dateTo,
        int $durationMinutes,
        ?string $displayTimezone = null,
    ): AvailabilityResult {
        $organization = $this->context->organization();

        if ((int) $client->organization_id !== $organization->getKey()
            || (int) $specialist->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The scheduling records are outside the current organization.');
        }

        $requestedDateFrom = LocalDate::from($dateFrom);
        $requestedDateTo = LocalDate::from($dateTo);
        if ($this->dateCount($requestedDateFrom, $requestedDateTo) > 31) {
            throw ValidationException::withMessages(['dateTo' => 'The availability range cannot exceed 31 days.']);
        }

        $scheduleTimezone = $this->scheduleTimezone($specialist);
        $resolvedDisplayTimezone = $this->displayTimezone($displayTimezone, $client, $scheduleTimezone);
        $displayRangeStart = $this->localBoundary($requestedDateFrom, $resolvedDisplayTimezone);
        $displayRangeEnd = $this->localBoundary($requestedDateTo->nextDay(), $resolvedDisplayTimezone);
        $scheduleDateFrom = LocalDate::from($displayRangeStart->setTimezone($scheduleTimezone)->toDateString());
        $scheduleDateTo = LocalDate::from($displayRangeEnd->subSecond()->setTimezone($scheduleTimezone)->toDateString());

        return $this->calculateForModels(
            specialist: $specialist,
            service: null,
            dateFrom: $scheduleDateFrom,
            dateTo: $scheduleDateTo,
            format: VisitFormat::Online,
            displayTimezone: $displayTimezone,
            client: $client,
            organizationId: $organization->getKey(),
            displayRangeStart: $displayRangeStart,
            displayRangeEnd: $displayRangeEnd,
            displayDateFrom: $requestedDateFrom->value,
            displayDateTo: $requestedDateTo->value,
            maxDateCount: 33,
            durationMinutes: $durationMinutes,
            bufferMinutes: 0,
            maxSlots: (int) config('b2b.availability.max_slots', 200),
        );
    }

    public function forBooking(
        Specialist $specialist,
        Service $service,
        VisitFormat $format,
        CarbonImmutable $startsAt,
        ?string $displayTimezone = null,
        ?int $ignoreBookingId = null,
        ?int $leadTimeMinutes = null,
        ?CarbonImmutable $now = null,
        ?int $workingLocationId = null,
        ?string $locationArea = null,
        bool $allowInactiveLocation = false,
    ): AvailabilityResult {
        $organization = $this->context->organization();
        $locationDays = $format === VisitFormat::HomeVisit
            ? $this->locations->activeLocationDays($locationArea)
            : null;

        $locationSelection = $this->locations->selection(
            format: $format,
            workingLocationId: $workingLocationId,
            areaName: $locationArea,
            startsAt: $startsAt->utc(),
            allowInactiveLocation: $allowInactiveLocation,
        );
        $scheduleTimezone = $this->locations->scheduleTimezone($specialist, $format, $locationSelection);
        $scheduleDate = LocalDate::from($startsAt->setTimezone($scheduleTimezone)->toDateString());

        return $this->calculateForModels(
            specialist: $specialist,
            service: $service,
            dateFrom: $scheduleDate,
            dateTo: $scheduleDate,
            format: $format,
            displayTimezone: $displayTimezone,
            client: null,
            organizationId: $organization->getKey(),
            ignoreBookingId: $ignoreBookingId,
            leadTimeMinutes: $leadTimeMinutes,
            now: $now,
            durationMinutes: $service->durationMinutes() ?? 0,
            bufferMinutes: $service->buffer_minutes,
            workingLocation: $locationSelection->workingLocation,
            locationArea: $locationArea,
            locationDays: $locationDays?->isNotEmpty() ? $locationDays : null,
        );
    }

    public function isExistingBookingAligned(Booking $booking): bool
    {
        $booking->loadMissing(['specialist', 'service', 'workingLocation']);
        $specialist = $booking->specialist;
        $service = $booking->service;

        if ((int) $booking->organization_id !== $this->context->id()) {
            return false;
        }

        $scheduleTimezone = $this->bookingScheduleTimezone($booking, $specialist);
        $durationMinutes = $service->durationMinutes();

        if ($booking->schedule_timezone !== $scheduleTimezone
            || ! $specialist->is_active
            || ! $service->is_active
            || $service->catalogItemType() !== CatalogItemType::Service
            || $durationMinutes === null
            || ! in_array($booking->visit_format->value, $service->supportedFormats(), true)
            || ! $this->eligibility->exists($this->context->id(), $specialist->getKey(), $service->getKey())) {
            return false;
        }

        $availability = $this->forBooking(
            specialist: $specialist,
            service: $service,
            format: $booking->visit_format,
            startsAt: $booking->startsAtUtc(),
            displayTimezone: $scheduleTimezone,
            ignoreBookingId: $booking->getKey(),
            leadTimeMinutes: 0,
            now: $booking->startsAtUtc()->subSecond(),
            workingLocationId: $booking->working_location_id,
            locationArea: $booking->location_area,
            allowInactiveLocation: true,
        );

        foreach ($availability->slots as $slot) {
            if ($slot->startsAt->equalTo($booking->startsAtUtc())
                && $slot->endsAt->equalTo($booking->endsAtUtc())
                && $slot->blockingEndsAt->equalTo($booking->blockingEndsAtUtc())) {
                return true;
            }
        }

        return false;
    }

    private function calculateForIds(
        int $specialistId,
        int $serviceId,
        string $dateFrom,
        string $dateTo,
        VisitFormat $format,
        ?string $displayTimezone,
        ?Client $client = null,
        bool $datesInDisplayTimezone = false,
        ?int $workingLocationId = null,
        ?string $locationArea = null,
    ): AvailabilityResult {
        $organization = $this->context->organization();
        $specialist = Specialist::query()->find($specialistId);
        $service = Service::query()->find($serviceId);

        if (! $specialist instanceof Specialist || (int) $specialist->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The specialist is outside the current organization.');
        }

        if (! $service instanceof Service || (int) $service->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The service is outside the current organization.');
        }

        $requestedDateFrom = LocalDate::from($dateFrom);
        $requestedDateTo = LocalDate::from($dateTo);
        if ($this->dateCount($requestedDateFrom, $requestedDateTo) > 31) {
            throw ValidationException::withMessages(['dateTo' => 'The availability range cannot exceed 31 days.']);
        }
        $scheduleDateFrom = $requestedDateFrom;
        $scheduleDateTo = $requestedDateTo;
        $displayRangeStart = null;
        $displayRangeEnd = null;

        $workingLocation = $format === VisitFormat::Office
            ? $this->locations->officeLocation($workingLocationId)
            : null;
        $locationDays = $format === VisitFormat::HomeVisit
            ? $this->locations->activeLocationDays($locationArea)
            : null;
        if ($format === VisitFormat::HomeVisit
            && $this->locations->hasActiveLocationDays()
            && ($locationArea === null || trim($locationArea) === '' || $locationDays?->isEmpty())) {
            throw ValidationException::withMessages([
                'location_area' => 'Для этого района нет подходящего дня выезда.',
            ]);
        }
        $scheduleTimezone = $this->scheduleTimezoneForSelection($specialist, $format, $workingLocation, $locationDays);

        if ($datesInDisplayTimezone) {
            $resolvedDisplayTimezone = $this->displayTimezone($displayTimezone, $client, $scheduleTimezone);
            $displayRangeStart = $this->localBoundary($requestedDateFrom, $resolvedDisplayTimezone);
            $displayRangeEnd = $this->localBoundary($requestedDateTo->nextDay(), $resolvedDisplayTimezone);
            $scheduleDateFrom = LocalDate::from($displayRangeStart->setTimezone($scheduleTimezone)->toDateString());
            $scheduleDateTo = LocalDate::from($displayRangeEnd->subSecond()->setTimezone($scheduleTimezone)->toDateString());
        }

        return $this->calculateForModels(
            specialist: $specialist,
            service: $service,
            dateFrom: $scheduleDateFrom,
            dateTo: $scheduleDateTo,
            format: $format,
            displayTimezone: $displayTimezone,
            client: $client,
            organizationId: $organization->getKey(),
            displayRangeStart: $displayRangeStart,
            displayRangeEnd: $displayRangeEnd,
            displayDateFrom: $datesInDisplayTimezone ? $requestedDateFrom->value : null,
            displayDateTo: $datesInDisplayTimezone ? $requestedDateTo->value : null,
            maxDateCount: $datesInDisplayTimezone ? 33 : 31,
            durationMinutes: $service->durationMinutes() ?? 0,
            bufferMinutes: $service->buffer_minutes,
            workingLocation: $workingLocation,
            locationArea: $locationArea,
            locationDays: $locationDays,
        );
    }

    /** @param Collection<int, LocationDay>|null $locationDays */
    private function calculateForModels(
        Specialist $specialist,
        ?Service $service,
        LocalDate $dateFrom,
        LocalDate $dateTo,
        VisitFormat $format,
        ?string $displayTimezone,
        ?Client $client,
        int $organizationId,
        ?int $ignoreBookingId = null,
        ?CarbonImmutable $displayRangeStart = null,
        ?CarbonImmutable $displayRangeEnd = null,
        ?string $displayDateFrom = null,
        ?string $displayDateTo = null,
        int $maxDateCount = 31,
        ?int $leadTimeMinutes = null,
        ?CarbonImmutable $now = null,
        int $durationMinutes = 0,
        int $bufferMinutes = 0,
        ?int $maxSlots = null,
        ?WorkingLocation $workingLocation = null,
        ?string $locationArea = null,
        /** @var Collection<int, LocationDay>|null $locationDays */
        ?Collection $locationDays = null,
    ): AvailabilityResult {
        if ($dateFrom->value > $dateTo->value) {
            throw ValidationException::withMessages(['dateFrom' => 'The availability range is invalid.']);
        }

        $dateCount = $this->dateCount($dateFrom, $dateTo);

        if ($dateCount > $maxDateCount) {
            throw ValidationException::withMessages(['dateTo' => 'The availability range cannot exceed 31 days.']);
        }

        if ((int) $specialist->organization_id !== $organizationId
            || ($service !== null && (int) $service->organization_id !== $organizationId)) {
            throw new AuthorizationException('The scheduling records are outside the current organization.');
        }

        $scheduleTimezone = $this->scheduleTimezoneForSelection($specialist, $format, $workingLocation, $locationDays);
        $resolvedDisplayTimezone = $this->displayTimezone($displayTimezone, $client, $scheduleTimezone);

        if ($service !== null && ! $this->eligibility->exists($organizationId, $specialist->getKey(), $service->getKey())) {
            return new AvailabilityResult(
                specialistId: $specialist->getKey(),
                serviceId: $service->getKey(),
                scheduleTimezone: $scheduleTimezone,
                displayTimezone: $resolvedDisplayTimezone,
                slots: [],
            );
        }

        $dateEnd = $dateTo->nextDay();
        $rangeStart = $this->localBoundary($dateFrom, $scheduleTimezone)->subDays(2);
        $rangeEnd = $this->localBoundary($dateEnd, $scheduleTimezone)->addDays(2);

        $workingHours = SpecialistWorkingHour::query()
            ->where('organization_id', $organizationId)
            ->where('specialist_id', $specialist->getKey())
            ->where('is_active', true)
            ->get()
            ->groupBy('weekday');
        $exceptions = ScheduleException::query()
            ->where('organization_id', $organizationId)
            ->where('specialist_id', $specialist->getKey())
            ->whereBetween('exception_date', [$dateFrom->value, $dateTo->value])
            ->where('is_active', true)
            ->get()
            ->groupBy(fn (ScheduleException $exception): string => $exception->dateKey());
        $unavailableIntervals = array_values(UnavailablePeriod::query()
            ->where('organization_id', $organizationId)
            ->where('specialist_id', $specialist->getKey())
            ->where('starts_at', '<', $rangeEnd)
            ->where('ends_at', '>', $rangeStart)
            ->get()
            ->map(fn (UnavailablePeriod $period): InstantInterval => $period->instantInterval())
            ->all());
        $bookingIntervals = array_values(Booking::query()
            ->where('organization_id', $organizationId)
            ->where('specialist_id', $specialist->getKey())
            ->when($ignoreBookingId !== null, fn ($query) => $query->where('id', '<>', $ignoreBookingId))
            ->whereIn('status', BookingStatus::blockingValues())
            ->where('starts_at', '<', $rangeEnd)
            ->where('blocking_ends_at', '>', $rangeStart)
            ->get()
            ->map(fn (Booking $booking): InstantInterval => $booking->instantInterval())
            ->all());
        $slots = [];
        $now = $now ?? CarbonImmutable::instance(now())->utc();
        $leadTimeMinutes ??= $this->leadTime->handle();
        $effectiveBufferMinutes = $bufferMinutes + ($format === VisitFormat::HomeVisit ? $this->homeVisitBuffer->handle() : 0);

        if (! $specialist->is_active
            || ($service !== null && (! $service->is_active
                || $service->catalogItemType() !== CatalogItemType::Service
                || ! in_array($format->value, $service->supportedFormats(), true)))
            || $durationMinutes < 1) {
            return new AvailabilityResult(
                specialistId: $specialist->getKey(),
                serviceId: $service?->getKey(),
                scheduleTimezone: $scheduleTimezone,
                displayTimezone: $resolvedDisplayTimezone,
                slots: [],
            );
        }

        $cursor = $dateFrom;

        for ($index = 0; $index < $dateCount; $index++) {
            $matchingLocationDays = $format === VisitFormat::HomeVisit && $locationDays?->isNotEmpty()
                ? $this->locations->matchingLocationDaysForDate((string) $locationArea, $cursor, $locationDays)
                : (new LocationDay)->newCollection();
            if ($format === VisitFormat::HomeVisit && $locationDays?->isNotEmpty() && $matchingLocationDays->isEmpty()) {
                $cursor = $cursor->nextDay();

                continue;
            }

            $dayTimezone = $matchingLocationDays->isNotEmpty()
                ? $this->locations->scheduleTimezoneForLocationDays($specialist, $matchingLocationDays)
                : $scheduleTimezone;
            $dayDate = $matchingLocationDays->isNotEmpty()
                ? $cursor
                : LocalDate::from(
                    $this->localBoundary($cursor, $scheduleTimezone)->setTimezone($dayTimezone)->toDateString(),
                );
            $allowedIntervals = [];
            foreach ($matchingLocationDays as $locationDay) {
                $locationInterval = $this->calculator->wallClockInterval(
                    $dayDate,
                    $locationDay->timezone,
                    $locationDay->wallClockInterval(),
                );
                if ($locationInterval === null) {
                    continue;
                }
                $allowedIntervals[] = $locationInterval;
            }
            if ($matchingLocationDays->isNotEmpty() && $allowedIntervals === []) {
                $cursor = $cursor->nextDay();

                continue;
            }

            $dateExceptions = $exceptions->get($dayDate->value, collect());
            $dayOff = $dateExceptions->contains(
                fn (ScheduleException $exception): bool => $exception->exception_type === ScheduleExceptionType::DayOff,
            );
            $customIntervals = array_values($dateExceptions
                ->filter(fn (ScheduleException $exception): bool => $exception->exception_type === ScheduleExceptionType::CustomWindow)
                ->map(fn (ScheduleException $exception): ?WallClockInterval => $exception->wallClockInterval())
                ->filter()
                ->values()
                ->all());
            $workingIntervals = array_values($workingHours->get($dayDate->weekday(), collect())
                ->map(fn (SpecialistWorkingHour $workingHour): WallClockInterval => $workingHour->wallClockInterval())
                ->all());

            $slots = [
                ...$slots,
                ...$this->calculator->calculate(
                    date: $dayDate,
                    scheduleTimezone: $dayTimezone,
                    workingIntervals: $workingIntervals,
                    customIntervals: $customIntervals,
                    dayOff: $dayOff,
                    unavailableIntervals: $unavailableIntervals,
                    bookingIntervals: $bookingIntervals,
                    durationMinutes: $durationMinutes,
                    bufferMinutes: $effectiveBufferMinutes,
                    leadTimeMinutes: $leadTimeMinutes,
                    now: $now,
                    format: $format,
                    displayTimezone: $resolvedDisplayTimezone,
                    allowedIntervals: $allowedIntervals,
                ),
            ];
            $cursor = $cursor->nextDay();
        }

        if ($displayRangeStart !== null && $displayRangeEnd !== null) {
            $slots = array_values(array_filter(
                $slots,
                static function (AvailabilitySlot $slot) use (
                    $displayRangeStart,
                    $displayRangeEnd,
                    $displayDateFrom,
                    $displayDateTo,
                    $resolvedDisplayTimezone,
                ): bool {
                    if ($slot->startsAt->lessThan($displayRangeStart)
                        || $slot->startsAt->greaterThanOrEqualTo($displayRangeEnd)) {
                        return false;
                    }

                    if ($displayDateFrom === null || $displayDateTo === null) {
                        return true;
                    }

                    $displayDate = $slot->startsAt->setTimezone($resolvedDisplayTimezone)->toDateString();

                    return $displayDate >= $displayDateFrom && $displayDate <= $displayDateTo;
                },
            ));
        }

        return new AvailabilityResult(
            specialistId: $specialist->getKey(),
            serviceId: $service?->getKey(),
            scheduleTimezone: $scheduleTimezone,
            displayTimezone: $resolvedDisplayTimezone,
            slots: $maxSlots === null ? $slots : array_slice($slots, 0, max(0, $maxSlots)),
        );
    }

    private function scheduleTimezone(Specialist $specialist): string
    {
        $timezone = $specialist->timezone ?? $this->context->organization()->defaultTimezone();

        return IanaTimezone::from($timezone)->value;
    }

    /** @param Collection<int, LocationDay>|null $locationDays */
    private function scheduleTimezoneForSelection(
        Specialist $specialist,
        VisitFormat $format,
        ?WorkingLocation $workingLocation,
        ?Collection $locationDays,
    ): string {
        return match ($format) {
            VisitFormat::Office => $workingLocation instanceof WorkingLocation
                ? $workingLocation->timezone
                : $this->scheduleTimezone($specialist),
            VisitFormat::HomeVisit => $this->locations->scheduleTimezoneForLocationDays($specialist, $locationDays),
            VisitFormat::Online => $this->scheduleTimezone($specialist),
        };
    }

    private function bookingScheduleTimezone(Booking $booking, Specialist $specialist): string
    {
        if ($booking->visit_format === VisitFormat::Office && $booking->workingLocation instanceof WorkingLocation) {
            return IanaTimezone::from($booking->workingLocation->timezone)->value;
        }

        if ($booking->visit_format === VisitFormat::HomeVisit && $booking->location_area !== null) {
            $locationDays = $this->locations->activeLocationDays($booking->location_area);
            if ($locationDays->isNotEmpty()) {
                $matchingLocationDays = $this->locations->matchingLocationDays(
                    $booking->location_area,
                    $booking->startsAtUtc(),
                    $locationDays,
                );
                if ($matchingLocationDays->isNotEmpty()) {
                    return $this->locations->scheduleTimezoneForLocationDays($specialist, $matchingLocationDays);
                }
            }
        }

        return $this->scheduleTimezone($specialist);
    }

    private function displayTimezone(?string $displayTimezone, ?Client $client, string $scheduleTimezone): string
    {
        $timezone = $displayTimezone ?? ($client !== null ? $client->timezone : null) ?? $scheduleTimezone;

        try {
            return IanaTimezone::from($timezone)->value;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['timezone' => 'The display timezone must be an IANA timezone.']);
        }
    }

    private function dateCount(LocalDate $dateFrom, LocalDate $dateTo): int
    {
        [$fromYear, $fromMonth, $fromDay] = array_map('intval', explode('-', $dateFrom->value));
        [$toYear, $toMonth, $toDay] = array_map('intval', explode('-', $dateTo->value));
        $from = CarbonImmutable::createSafe($fromYear, $fromMonth, $fromDay, 0, 0, 0, new DateTimeZone('UTC'));
        $to = CarbonImmutable::createSafe($toYear, $toMonth, $toDay, 0, 0, 0, new DateTimeZone('UTC'));

        if (! $from instanceof CarbonImmutable || ! $to instanceof CarbonImmutable) {
            throw new InvalidArgumentException('The availability date range is invalid.');
        }

        return (int) $from->diffInDays($to) + 1;
    }

    private function localBoundary(LocalDate $date, string $timezone): CarbonImmutable
    {
        [$year, $month, $day] = array_map('intval', explode('-', $date->value));
        $wallStart = CarbonImmutable::createSafe($year, $month, $day, 0, 0, 0, new DateTimeZone('UTC'));
        if (! $wallStart instanceof CarbonImmutable) {
            throw new InvalidArgumentException('The availability date is invalid in the schedule timezone.');
        }

        $wallEnd = $wallStart->addDay();
        $zone = new DateTimeZone($timezone);
        $windowStart = $wallStart->subDays(3)->getTimestamp();
        $windowEnd = $wallEnd->addDays(3)->getTimestamp();
        $transitions = $zone->getTransitions($windowStart, $windowEnd);

        if ($transitions === []) {
            throw new InvalidArgumentException('The availability date is invalid in the schedule timezone.');
        }

        $earliest = null;
        $latest = null;

        foreach ($transitions as $index => $transition) {
            $segmentStart = max($windowStart, (int) $transition['ts']);
            $segmentEnd = $index + 1 < count($transitions)
                ? min($windowEnd, (int) $transitions[$index + 1]['ts'])
                : $windowEnd;
            $offset = (int) $transition['offset'];
            $candidateStart = max($segmentStart, $wallStart->getTimestamp() - $offset);
            $candidateEnd = min($segmentEnd, $wallEnd->getTimestamp() - $offset);

            if ($candidateStart >= $candidateEnd) {
                continue;
            }

            $earliest = $earliest === null ? $candidateStart : min($earliest, $candidateStart);
            $latest = $latest === null ? $candidateEnd : max($latest, $candidateEnd);
        }

        if ($earliest === null || $latest === null) {
            throw new InvalidArgumentException('The availability date has no valid instants in the schedule timezone.');
        }

        return CarbonImmutable::createFromTimestampUTC($earliest);
    }
}
