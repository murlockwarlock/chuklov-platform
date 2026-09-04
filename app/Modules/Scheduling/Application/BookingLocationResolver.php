<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\LocationDay;
use App\Modules\Scheduling\Domain\Models\WorkingLocation;
use App\Modules\Scheduling\Domain\ValueObjects\BookingLocationSelection;
use App\Modules\Scheduling\Domain\ValueObjects\LocalDate;
use App\Modules\Scheduling\Domain\ValueObjects\LocationDayDefinition;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
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

    /**
     * @param  Collection<int, LocationDay>|null  $locationDays
     * @return Collection<int, LocationDay>
     */
    public function matchingLocationDays(
        string $areaName,
        CarbonImmutable $startsAt,
        ?Collection $locationDays = null,
    ): Collection {
        $locationDays ??= $this->activeLocationDays($areaName);
        if ($locationDays->isEmpty()) {
            return new Collection;
        }

        $timezone = $this->referenceTimezone($locationDays);
        $date = LocalDate::from($startsAt->setTimezone($timezone)->toDateString());

        return $this->matchingLocationDaysForDate($areaName, $date, $locationDays);
    }

    /**
     * @param  Collection<int, LocationDay>|null  $locationDays
     * @return Collection<int, LocationDay>
     */
    public function matchingLocationDaysForDate(
        string $areaName,
        LocalDate $date,
        ?Collection $locationDays = null,
    ): Collection {
        $normalizedArea = mb_strtolower(trim($areaName));
        if ($normalizedArea === '') {
            return new Collection;
        }

        $locationDays ??= $this->activeLocationDays($areaName);
        $areaRules = $locationDays->filter(
            fn (LocationDay $locationDay): bool => mb_strtolower(trim($locationDay->area_name)) === $normalizedArea,
        );
        $specificRules = $areaRules->filter(
            fn (LocationDay $locationDay): bool => $this->specificDateKey($locationDay) === $date->value,
        );
        $applicableRules = $specificRules->isNotEmpty()
            ? $specificRules
            : $areaRules->filter(
                fn (LocationDay $locationDay): bool => $this->specificDateKey($locationDay) === null
                    && $locationDay->weekday !== null
                    && (int) $locationDay->weekday === $date->weekday(),
            );

        $this->ensureSingleTimezone($applicableRules);

        return $applicableRules
            ->sortBy(fn (LocationDay $locationDay): string => sprintf(
                '%s|%s|%010d',
                (string) $locationDay->start_time,
                (string) $locationDay->end_time,
                (int) $locationDay->getKey(),
            ))
            ->values();
    }

    /**
     * @param  Collection<int, LocationDay>|null  $locationDays
     */
    public function matchingLocationDay(
        string $areaName,
        CarbonImmutable $startsAt,
        ?Collection $locationDays = null,
    ): ?LocationDay {
        return $this->matchingLocationDays($areaName, $startsAt, $locationDays)->first();
    }

    /** @param Collection<int, LocationDay>|null $locationDays */
    public function scheduleTimezoneForLocationDays(Specialist $specialist, ?Collection $locationDays): string
    {
        $timezones = $this->validatedTimezones($locationDays);
        $timezone = $timezones->count() === 1 ? $timezones->first() : null;
        $timezone ??= $specialist->timezone ?? $this->context->organization()->defaultTimezone();

        return IanaTimezone::from($timezone)->value;
    }

    public function ensureTimezoneCompatibility(LocationDayDefinition $definition, ?int $ignoreLocationDayId = null): void
    {
        if (! $definition->isActive) {
            return;
        }

        $normalizedArea = mb_strtolower($definition->areaName);
        $existingRules = LocationDay::query()
            ->where('organization_id', $this->context->id())
            ->whereRaw('LOWER(area_name) = ?', [$normalizedArea])
            ->where('is_active', true)
            ->when($ignoreLocationDayId !== null, fn ($query) => $query->where('id', '<>', $ignoreLocationDayId))
            ->get();

        foreach ($existingRules as $existingRule) {
            if (! $this->rulesShareApplicability($definition, $existingRule)) {
                continue;
            }

            if ($this->validatedTimezone($existingRule, 'timezone') !== $definition->timezone) {
                throw ValidationException::withMessages([
                    'timezone' => 'Конфликтующие правила одного района и дня должны использовать один часовой пояс.',
                ]);
            }
        }
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

        $locationDays = $this->matchingLocationDays($areaName, $startsAt);
        if ($locationDays->isEmpty()) {
            throw ValidationException::withMessages([
                'location_area' => 'Для этого района нет подходящего дня выезда.',
            ]);
        }

        return new BookingLocationSelection(null, $locationDays->first());
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

    /** @param Collection<int, LocationDay> $locationDays */
    private function referenceTimezone(Collection $locationDays): string
    {
        $timezones = $this->validatedTimezones($locationDays);

        return $timezones->count() === 1
            ? (string) $timezones->first()
            : $this->context->organization()->defaultTimezone();
    }

    /** @param Collection<int, LocationDay> $locationDays */
    private function ensureSingleTimezone(Collection $locationDays): void
    {
        if ($this->validatedTimezones($locationDays)->count() > 1) {
            throw ValidationException::withMessages([
                'location_area' => 'Конфликтующие правила одного района и дня должны использовать один часовой пояс.',
            ]);
        }
    }

    /** @param Collection<int, LocationDay>|null $locationDays
     * @return SupportCollection<int, string>
     */
    private function validatedTimezones(?Collection $locationDays): SupportCollection
    {
        return ($locationDays ?? new Collection)
            ->map(fn (LocationDay $locationDay): string => $this->validatedTimezone($locationDay))
            ->unique()
            ->values();
    }

    private function validatedTimezone(LocationDay $locationDay, string $field = 'location_area'): string
    {
        try {
            return IanaTimezone::from((string) $locationDay->timezone)->value;
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([
                $field => 'Правило дня выезда содержит некорректный часовой пояс.',
            ]);
        }
    }

    private function specificDateKey(LocationDay $locationDay): ?string
    {
        return $locationDay->specific_date === null
            ? null
            : CarbonImmutable::parse((string) $locationDay->specific_date)->toDateString();
    }

    private function rulesShareApplicability(LocationDayDefinition $definition, LocationDay $existingRule): bool
    {
        $existingSpecificDate = $this->specificDateKey($existingRule);

        if ($definition->specificDate !== null) {
            return $definition->specificDate === $existingSpecificDate;
        }

        return $existingSpecificDate === null
            && $definition->weekday !== null
            && $existingRule->weekday !== null
            && $definition->weekday === (int) $existingRule->weekday;
    }
}
