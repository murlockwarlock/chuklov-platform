<?php

namespace App\Filament\Resources\UnavailablePeriods\Schemas;

use App\Filament\Support\ScheduleImpactPreview;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UnavailablePeriodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('specialist_id')
                    ->label('Специалист')
                    ->options(fn (): array => Specialist::query()
                        ->where('organization_id', app(OrganizationContext::class)->id())
                        ->orderBy('display_name')
                        ->pluck('display_name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
                DateTimePicker::make('starts_at')
                    ->label('Начало')
                    ->seconds(false)
                    ->timezone(fn (): string => app(OrganizationContext::class)->organization()->defaultTimezone())
                    ->required(),
                DateTimePicker::make('ends_at')
                    ->label('Окончание')
                    ->seconds(false)
                    ->timezone(fn (): string => app(OrganizationContext::class)->organization()->defaultTimezone())
                    ->required(),
                TextInput::make('reason')
                    ->label('Причина')
                    ->maxLength(500),
                ...ScheduleImpactPreview::components(),
            ]);
    }
}
