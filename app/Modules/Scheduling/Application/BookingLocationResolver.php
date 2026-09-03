<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\LocationDay;
use App\Modules\Scheduling\Domain\Models\WorkingLocation;
use App\Modules\Scheduling\Domain\ValueObjects\BookingLocationSelection;
use App\Modules\Scheduling\Domain\ValueObjects\LocalDate;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final readonly class BookingLocationResolver
{
    public function __construct(private OrganizationContext $context) {}

    /** @return Collection<int, WorkingLocation> */
    public function activeOfficeLocations(): Collection
    {
        return WorkingLocation::query()
            ->where('organization_id', $this->context->id())
            ->where('is_active', true)
            ->orderByDesc('is_default_office')
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, LocationDay> */
    public function activeLocationDays(?string $areaName = null): Collection
    {
        $normalizedArea = $areaName === null ? null : mb_strtolower(trim($areaName));

        return LocationDay::query()
            ->where('organization_id', $this->context->id())
            ->when($normalizedArea !== null, fn ($query) => $query->whereRaw('LOWER(area_name) = ?', [$normalizedArea]))
            ->where('is_active', true)
            ->orderBy('area_name')
            ->orderBy('specific_date')
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get();
    }

    public function hasActiveLocationDays(): bool
    {
        return LocationDay::query()
            ->where('organization_id', $this->context->id())
            ->where('is_active', true)
            ->exists();
    }

    /** @param Collection<int, LocationDay>|null $locationDays */
    public function matchingLocationDay(
        string $areaName,
        CarbonImmutable $startsAt,
        ?Collection $locationDays = null,
    ): ?LocationDay {
        $areaName = trim($areaName);
        if ($areaName === '') {
            return null;
        }

        $locationDays ??= $this->activeLocationDays($areaName);

        foreach ($locationDays as $locationDay) {
            $localDate = LocalDate::from($startsAt->setTimezone($locationDay->timezone)->toDateString());
            if ($locationDay->appliesTo($localDate)) {
                return $locationDay;
            }
        }

        return null;
    }

    public function selection(
        VisitFormat $format,
        ?int $workingLocationId,
        ?string $areaName,
        CarbonImmutable $startsAt,
        bool $allowInactiveLocation = false,
    ): BookingLocationSelection {
        if ($format === VisitFormat::Office) {
            return new BookingLocationSelection(
                workingLocation: $this->officeLocationForId($workingLocationId, $allowInactiveLocation),
                locationDay: null,
            );
        }

        if ($format !== VisitFormat::HomeVisit) {
            return new BookingLocationSelection(null, null);
        }

        if (! $this->hasActiveLocationDays()) {
            return new BookingLocationSelection(null, null);
        }

        $areaName = trim((string) $areaName);
        if ($areaName === '') {
            throw ValidationException::withMessages([
                'location_area' => 'Укажите район выезда.',
            ]);
        }

        $locationDay = $this->matchingLocationDay($areaName, $startsAt);
        if (! $locationDay instanceof LocationDay) {
            throw ValidationException::withMessages([
                'location_area' => 'Для этого района нет подходящего дня выезда.',
            ]);
        }

        return new BookingLocationSelection(null, $locationDay);
    }

    public function scheduleTimezone(
        Specialist $specialist,
        VisitFormat $format,
        BookingLocationSelection $selection,
    ): string {
        $timezone = match ($format) {
            VisitFormat::Office => $selection->workingLocation?->timezone,
            VisitFormat::HomeVisit => $selection->locationDay?->timezone,
            VisitFormat::Online => null,
        } ?? $specialist->timezone ?? $this->context->organization()->defaultTimezone();

        return IanaTimezone::from($timezone)->value;
    }

    public function officeLocation(?int $workingLocationId = null): ?WorkingLocation
    {
        return $this->officeLocationForId($workingLocationId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function snapshot(
        VisitFormat $format,
        BookingLocationSelection $selection,
        string $scheduleTimezone,
        ?string $address,
        ?string $areaName,
        ?float $latitude,
        ?float $longitude,
        ?string $mapUrl,
    ): ?array {
        if ($format === VisitFormat::Online) {
            return null;
        }

        if ($format === VisitFormat::Office) {
            $location = $selection->workingLocation;
            $resolvedAddress = $location instanceof WorkingLocation
                ? $location->address
                : $address;

            if ($resolvedAddress === null || trim($resolvedAddress) === '') {
                return null;
            }

            $locationName = $location instanceof WorkingLocation ? $location->name : 'Основной кабинет';
            $locationTimezone = $location instanceof WorkingLocation ? $location->timezone : $scheduleTimezone;
            $locationMapUrl = $location instanceof WorkingLocation ? $location->map_url : $mapUrl;

            return [
                'type' => VisitFormat::Office->value,
                'name' => $locationName,
                'address' => trim($resolvedAddress),
                'timezone' => $locationTimezone,
                'latitude' => $location?->latitude === null ? $latitude : (float) $location->latitude,
                'longitude' => $location?->longitude === null ? $longitude : (float) $location->longitude,
                'map_url' => $locationMapUrl,
            ];
        }

        return [
            'type' => VisitFormat::HomeVisit->value,
            'area_name' => $areaName === null ? null : trim($areaName),
            'address' => $address === null ? null : trim($address),
            'timezone' => $scheduleTimezone,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'map_url' => $mapUrl,
        ];
    }

    private function officeLocationForId(?int $workingLocationId, bool $allowInactiveLocation = false): ?WorkingLocation
    {
        $locations = $this->activeOfficeLocations();

        if ($workingLocationId !== null) {
            $location = WorkingLocation::query()
                ->where('organization_id', $this->context->id())
                ->whereKey($workingLocationId)
                ->first();

            if (! $location instanceof WorkingLocation) {
                throw new AuthorizationException('The working location is outside the current organization.');
            }

            if (! $location->is_active && ! $allowInactiveLocation) {
                throw ValidationException::withMessages([
                    'working_location_id' => 'Выбранная локация больше недоступна.',
                ]);
            }

            return $location;
        }

        if ($allowInactiveLocation) {
            return null;
        }

        if ($locations->count() > 1) {
            throw ValidationException::withMessages([
                'working_location_id' => 'Выберите локацию для приёма.',
            ]);
        }

        return $locations->first();
    }
}
