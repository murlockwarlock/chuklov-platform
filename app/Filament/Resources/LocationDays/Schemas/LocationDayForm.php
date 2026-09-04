<?php

namespace App\Filament\Resources\LocationDays\Schemas;

use App\Filament\Support\TimezoneOptions;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class LocationDayForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('area_name')->label('Район')->required()->maxLength(160),
            Select::make('weekday')->label('День недели')->options([
                1 => 'Понедельник',
                2 => 'Вторник',
                3 => 'Среда',
                4 => 'Четверг',
                5 => 'Пятница',
                6 => 'Суббота',
                7 => 'Воскресенье',
            ])->nullable(),
            DatePicker::make('specific_date')->label('Или конкретная дата')->native(false)->nullable(),
            TimePicker::make('start_time')->label('Начало')->seconds(false)->required(),
            TimePicker::make('end_time')->label('Окончание')->seconds(false)->required(),
            Select::make('timezone')
                ->label('Часовой пояс')
                ->options(fn (Get $get): array => TimezoneOptions::options(
                    current: $get('timezone'),
                    organization: app(OrganizationContext::class)->defaultTimezone(),
                ))
                ->searchable()
                ->required(),
            Toggle::make('is_active')->label('Активен')->default(true)->required(),
            TextInput::make('notes')->label('Примечание')->maxLength(500)->nullable(),
        ]);
    }
}
