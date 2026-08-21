<?php

namespace App\Filament\Resources\SpecialistServiceAssignments\Schemas;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class SpecialistServiceAssignmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('specialist_id')
                    ->label('Специалист')
                    ->searchable()
                    ->native(false)
                    ->preload()
                    ->optionsLimit(50)
                    ->options(static fn (): array => self::specialistResults(''))
                    ->getSearchResultsUsing(static fn (string $search): array => self::specialistResults($search))
                    ->getOptionLabelUsing(static fn (mixed $value): ?string => self::specialistLabel($value))
                    ->required(),
                Select::make('service_id')
                    ->label('Услуга')
                    ->searchable()
                    ->native(false)
                    ->preload()
                    ->optionsLimit(50)
                    ->options(static fn (): array => self::serviceResults(''))
                    ->getSearchResultsUsing(static fn (string $search): array => self::serviceResults($search))
                    ->getOptionLabelUsing(static fn (mixed $value): ?string => self::serviceLabel($value))
                    ->required(),
            ]);
    }

    /**
     * @return array<int|string, string>
     */
    private static function specialistResults(string $search): array
    {
        $normalizedSearch = trim($search);
        $query = Specialist::query()
            ->where('organization_id', self::organizationId())
            ->when($normalizedSearch !== '', fn (Builder $query): Builder => $query->where('display_name', 'like', '%'.$normalizedSearch.'%'))
            ->orderBy('display_name')
            ->limit(50);

        return $query->get(['id', 'display_name'])
            ->mapWithKeys(static fn (Specialist $specialist): array => [$specialist->getKey() => $specialist->display_name])
            ->all();
    }

    private static function specialistLabel(mixed $value): ?string
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $specialist = Specialist::query()
            ->where('organization_id', self::organizationId())
            ->whereKey((int) $value)
            ->first(['id', 'display_name']);

        return $specialist instanceof Specialist ? $specialist->display_name : null;
    }

    /**
     * @return array<int|string, string>
     */
    private static function serviceResults(string $search): array
    {
        $normalizedSearch = trim($search);
        $query = Service::query()
            ->where('organization_id', self::organizationId())
            ->when($normalizedSearch !== '', fn (Builder $query): Builder => $query->where('name', 'like', '%'.$normalizedSearch.'%'))
            ->orderBy('name')
            ->limit(50);

        return $query->get(['id', 'name'])
            ->mapWithKeys(static fn (Service $service): array => [$service->getKey() => $service->name])
            ->all();
    }

    private static function serviceLabel(mixed $value): ?string
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $service = Service::query()
            ->where('organization_id', self::organizationId())
            ->whereKey((int) $value)
            ->first(['id', 'name']);

        return $service instanceof Service ? $service->name : null;
    }

    private static function organizationId(): int
    {
        return app(OrganizationContext::class)->id();
    }
}
