<?php

namespace App\Filament\Resources\LocationDays\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LocationDaysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('area_name')->label('Район')->searchable()->sortable(),
                TextColumn::make('weekday')->label('День')->formatStateUsing(fn (?int $state): string => self::weekdayLabel($state)),
                TextColumn::make('specific_date')->label('Дата')->date('d.m.Y'),
                TextColumn::make('start_time')->label('Начало'),
                TextColumn::make('end_time')->label('Окончание'),
                TextColumn::make('timezone')->label('Часовой пояс'),
                IconColumn::make('is_active')->label('Активен')->boolean(),
            ])
            ->defaultSort('area_name')
            ->recordActions([EditAction::make()->label('Изменить')]);
    }

    private static function weekdayLabel(?int $weekday): string
    {
        return [
            1 => 'Пн',
            2 => 'Вт',
            3 => 'Ср',
            4 => 'Чт',
            5 => 'Пт',
            6 => 'Сб',
            7 => 'Вс',
        ][$weekday ?? 0] ?? '';
    }
}
