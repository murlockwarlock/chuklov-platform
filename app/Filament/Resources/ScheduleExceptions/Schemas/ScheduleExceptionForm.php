<?php

namespace App\Filament\Resources\ScheduleExceptions\Schemas;

use App\Filament\Support\ScheduleImpactPreview;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ScheduleExceptionForm
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
                DatePicker::make('exception_date')
                    ->label('Дата')
                    ->required(),
                Select::make('exception_type')
                    ->label('Тип изменения')
                    ->options([
                        ScheduleExceptionType::DayOff->value => 'Выходной день',
                        ScheduleExceptionType::CustomWindow->value => 'Дополнительные часы',
                    ])
                    ->required()
                    ->live(),
                TimePicker::make('start_time')
                    ->label('Начало')
                    ->seconds(false)
                    ->visible(fn (Get $get): bool => $get('exception_type') === ScheduleExceptionType::CustomWindow->value),
                TimePicker::make('end_time')
                    ->label('Окончание')
                    ->seconds(false)
                    ->visible(fn (Get $get): bool => $get('exception_type') === ScheduleExceptionType::CustomWindow->value),
                TextInput::make('reason')
                    ->label('Причина')
                    ->maxLength(500),
                ...ScheduleImpactPreview::components(),
            ]);
    }
}
