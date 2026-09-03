<?php

namespace App\Filament\Resources\WorkingLocations\Schemas;

use App\Filament\Support\TimezoneOptions;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class WorkingLocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Название')->required()->maxLength(160),
            TextInput::make('address')->label('Адрес')->required()->maxLength(500),
            Select::make('timezone')
                ->label('Часовой пояс')
                ->options(fn (Get $get): array => TimezoneOptions::options(
                    current: $get('timezone'),
                    organization: app(OrganizationContext::class)->defaultTimezone(),
                ))
                ->searchable()
                ->required(),
            TextInput::make('latitude')->label('Широта')->numeric()->minValue(-90)->maxValue(90)->nullable(),
            TextInput::make('longitude')->label('Долгота')->numeric()->minValue(-180)->maxValue(180)->nullable(),
            TextInput::make('map_url')->label('Ссылка на карту')->url()->maxLength(2000)->nullable(),
            Toggle::make('is_default_office')->label('Основная')->helperText('Основная локация выбирается первой, когда доступен один кабинет.'),
            Toggle::make('is_active')->label('Активна')->default(true)->required(),
        ]);
    }
}
