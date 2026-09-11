<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Models\SpecialistWorkingHour;
use App\Modules\Scheduling\Domain\ValueObjects\LocalDate;
use App\Modules\Scheduling\Domain\ValueObjects\WallClockInterval;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

final readonly class ResolveSpecialistWorkingHours
{
    public function __construct(private OrganizationContext $context) {}

    /** @return Collection<int, SpecialistWorkingHour> */
    public function forRange(
        Specialist $specialist,
        LocalDate $dateFrom,
        LocalDate $dateTo,
    ): Collection {
        if ((int) $specialist->organization_id !== $this->context->id()) {
            throw new InvalidArgumentException('The specialist is outside the current organization.');
        }

        if ($dateFrom->value > $dateTo->value) {
            throw new InvalidArgumentException('The schedule date range is invalid.');
        }

        return SpecialistWorkingHour::query()
            ->where('organization_id', $this->context->id())
            ->where('specialist_id', $specialist->getKey())
            ->where('is_active', true)
            ->where(function (Builder $query) use ($dateTo): void {
                $query->whereNull('starts_on')->orWhere('starts_on', '<=', $dateTo->value);
            })
            ->where(function (Builder $query) use ($dateFrom): void {
                $query->whereNull('ends_on')->orWhere('ends_on', '>=', $dateFrom->value);
            })
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->orderBy('end_time')
            ->orderBy('id')
            ->get();
    }

    /** @param Collection<int, SpecialistWorkingHour> $workingHours
     * @return list<WallClockInterval>
     */
    public function intervalsForDate(Collection $workingHours, LocalDate $date): array
    {
        return $workingHours
            ->filter(fn (SpecialistWorkingHour $workingHour): bool => (int) $workingHour->weekday === $date->weekday()
                && $workingHour->appliesOn($date))
            ->sortBy(fn (SpecialistWorkingHour $workingHour): string => sprintf(
                '%s|%s|%010d',
                (string) $workingHour->start_time,
                (string) $workingHour->end_time,
                (int) $workingHour->getKey(),
            ))
            ->map(fn (SpecialistWorkingHour $workingHour): WallClockInterval => $workingHour->wallClockInterval())
            ->values()
            ->all();
    }
}
