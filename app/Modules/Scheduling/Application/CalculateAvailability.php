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
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use App\Modules\Scheduling\Domain\Models\SpecialistWorkingHour;
use App\Modules\Scheduling\Domain\Models\UnavailablePeriod;
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
    ) {}

    public function forStaff(
        User $actor,
        int $specialistId,
        int $serviceId,
        string $dateFrom,
        string $dateTo,
        VisitFormat $format,
        ?string $displayTimezone = null,
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
    ): AvailabilityResult {
        $organization = $this->context->organization();

        return $this->calculateForModels(
            specialist: $specialist,
            service: $service,
            dateFrom: LocalDate::from($startsAt->setTimezone($this->scheduleTimezone($specialist))->toDateString()),
            dateTo: LocalDate::from($startsAt->setTimezone($this->scheduleTimezone($specialist))->toDateString()),
            format: $format,
            displayTimezone: $displayTimezone,
            client: null,
            organizationId: $organization->getKey(),
            ignoreBookingId: $ignoreBookingId,
            leadTimeMinutes: $leadTimeMinutes,
            now: $now,
        );
    }

    public function isExistingBookingAligned(Booking $booking): bool
    {
        $booking->loadMissing(['specialist', 'service']);
        $specialist = $booking->specialist;
        $service = $booking->service;

        if ((int) $booking->organization_id !== $this->context->id()) {
            return false;
        }

        $scheduleTimezone = $this->scheduleTimezone($specialist);
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

        if ($datesInDisplayTimezone) {
            $scheduleTimezone = $this->scheduleTimezone($specialist);
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
            maxDateCount: $datesInDisplayTimezone ? 33 : 31,
        );
    }

    private function calculateForModels(
        Specialist $specialist,
        Service $service,
        LocalDate $dateFrom,
        LocalDate $dateTo,
        VisitFormat $format,
        ?string $displayTimezone,
        ?Client $client,
        int $organizationId,
        ?int $ignoreBookingId = null,
        ?CarbonImmutable $displayRangeStart = null,
        ?CarbonImmutable $displayRangeEnd = null,
        int $maxDateCount = 31,
        ?int $leadTimeMinutes = null,
        ?CarbonImmutable $now = null,
    ): AvailabilityResult {
        if ($dateFrom->value > $dateTo->value) {
            throw ValidationException::withMessages(['dateFrom' => 'The availability range is invalid.']);
        }

        $dateCount = $this->dateCount($dateFrom, $dateTo);

        if ($dateCount > $maxDateCount) {
            throw ValidationException::withMessages(['dateTo' => 'The availability range cannot exceed 31 days.']);
        }

        if ((int) $specialist->organization_id !== $organizationId
            || (int) $service->organization_id !== $organizationId) {
            throw new AuthorizationException('The scheduling records are outside the current organization.');
        }

        $scheduleTimezone = $this->scheduleTimezone($specialist);
        $resolvedDisplayTimezone = $this->displayTimezone($displayTimezone, $client, $scheduleTimezone);

        if (! $this->eligibility->exists($organizationId, $specialist->getKey(), $service->getKey())) {
            return new AvailabilityResult(
                specialistId: $specialist->getKey(),
                serviceId: $service->getKey(),
                scheduleTimezone: $scheduleTimezone,
                displayTimezone: $resolvedDisplayTimezone,
                slots: [],
            );
        }

        $dateEnd = $dateTo->nextDay();
        $rangeStart = $this->localBoundary($dateFrom, $scheduleTimezone);
        $rangeEnd = $this->localBoundary($dateEnd, $scheduleTimezone);

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
        $durationMinutes = $service->durationMinutes();
        $leadTimeMinutes ??= $this->leadTime->handle();

        if (! $specialist->is_active || ! $service->is_active || $service->catalogItemType() !== CatalogItemType::Service
            || $durationMinutes === null || ! in_array($format->value, $service->supportedFormats(), true)) {
            return new AvailabilityResult(
                specialistId: $specialist->getKey(),
                serviceId: $service->getKey(),
                scheduleTimezone: $scheduleTimezone,
                displayTimezone: $resolvedDisplayTimezone,
                slots: [],
            );
        }

        $cursor = $dateFrom;

        for ($index = 0; $index < $dateCount; $index++) {
            $dateExceptions = $exceptions->get($cursor->value, collect());
            $dayOff = $dateExceptions->contains(
                fn (ScheduleException $exception): bool => $exception->exception_type === ScheduleExceptionType::DayOff,
            );
            $customIntervals = array_values($dateExceptions
                ->filter(fn (ScheduleException $exception): bool => $exception->exception_type === ScheduleExceptionType::CustomWindow)
                ->map(fn (ScheduleException $exception): ?WallClockInterval => $exception->wallClockInterval())
                ->filter()
                ->values()
                ->all());
            $workingIntervals = array_values($workingHours->get($cursor->weekday(), collect())
                ->map(fn (SpecialistWorkingHour $workingHour): WallClockInterval => $workingHour->wallClockInterval())
                ->all());

            $slots = [
                ...$slots,
                ...$this->calculator->calculate(
                    date: $cursor,
                    scheduleTimezone: $scheduleTimezone,
                    workingIntervals: $workingIntervals,
                    customIntervals: $customIntervals,
                    dayOff: $dayOff,
                    unavailableIntervals: $unavailableIntervals,
                    bookingIntervals: $bookingIntervals,
                    durationMinutes: $durationMinutes,
                    bufferMinutes: $service->buffer_minutes,
                    leadTimeMinutes: $leadTimeMinutes,
                    now: $now,
                    format: $format,
                    displayTimezone: $resolvedDisplayTimezone,
                ),
            ];
            $cursor = $cursor->nextDay();
        }

        if ($displayRangeStart !== null && $displayRangeEnd !== null) {
            $slots = array_values(array_filter(
                $slots,
                static fn (AvailabilitySlot $slot): bool => $slot->startsAt->greaterThanOrEqualTo($displayRangeStart)
                    && $slot->startsAt->lessThan($displayRangeEnd),
            ));
        }

        return new AvailabilityResult(
            specialistId: $specialist->getKey(),
            serviceId: $service->getKey(),
            scheduleTimezone: $scheduleTimezone,
            displayTimezone: $resolvedDisplayTimezone,
            slots: $slots,
        );
    }

    private function scheduleTimezone(Specialist $specialist): string
    {
        $timezone = $specialist->timezone ?? $this->context->organization()->defaultTimezone();

        return IanaTimezone::from($timezone)->value;
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

        $boundary = CarbonImmutable::createSafe($year, $month, $day, 0, 0, 0, new DateTimeZone($timezone));

        if (! $boundary instanceof CarbonImmutable) {
            throw new InvalidArgumentException('The availability date is invalid in the schedule timezone.');
        }

        return $boundary->utc();
    }
}
