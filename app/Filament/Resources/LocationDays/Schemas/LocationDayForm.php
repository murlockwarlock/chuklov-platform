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
            TextInput::make('area_name')->label(__('Район'))->required()->maxLength(160),
            Select::make('weekday')->label(__('День недели'))->options([
                1 => __('Понедельник'),
                2 => __('Вторник'),
                3 => __('Среда'),
                4 => __('Четверг'),
                5 => __('Пятница'),
                6 => __('Суббота'),
                7 => __('Воскресенье'),
            ])->nullable(),
            DatePicker::make('specific_date')->label(__('Или конкретная дата'))->native(false)->nullable(),
            TimePicker::make('start_time')->label(__('Начало'))->seconds(false)->required(),
            TimePicker::make('end_time')->label(__('Окончание'))->seconds(false)->required(),
            Select::make('timezone')
                ->label(__('Часовой пояс'))
                ->options(fn (Get $get): array => TimezoneOptions::options(
                    current: $get('timezone'),
                    organization: app(OrganizationContext::class)->defaultTimezone(),
                ))
                ->searchable()
                ->required(),
            Toggle::make('is_active')->label(__('Активен'))->default(true)->required(),
            TextInput::make('notes')->label(__('Примечание'))->maxLength(500)->nullable(),
        ]);
    }
}
