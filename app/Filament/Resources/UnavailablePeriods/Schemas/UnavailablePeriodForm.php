<?php

namespace App\Filament\Resources\UnavailablePeriods\Schemas;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Forms\Components\Checkbox;
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
                    ->label('Specialist')
                    ->options(fn (): array => Specialist::query()
                        ->where('organization_id', app(OrganizationContext::class)->id())
                        ->orderBy('display_name')
                        ->pluck('display_name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
                DateTimePicker::make('starts_at')
                    ->label('Starts at (organization timezone)')
                    ->seconds(false)
                    ->timezone(fn (): string => app(OrganizationContext::class)->organization()->defaultTimezone())
                    ->required(),
                DateTimePicker::make('ends_at')
                    ->label('Ends at (organization timezone)')
                    ->seconds(false)
                    ->timezone(fn (): string => app(OrganizationContext::class)->organization()->defaultTimezone())
                    ->required(),
                TextInput::make('reason')
                    ->label('Reason')
                    ->maxLength(500),
                Checkbox::make('acknowledge_impact')
                    ->label('Acknowledge impact on future bookings if shown')
                    ->default(false),
                TextInput::make('impact_digest')
                    ->label('Current impact preview digest')
                    ->maxLength(64),
            ]);
    }
}
