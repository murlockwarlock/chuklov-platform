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
                TextColumn::make('area_name')->label(__('Район'))->searchable()->sortable(),
                TextColumn::make('weekday')->label(__('День'))->formatStateUsing(fn (?int $state): string => self::weekdayLabel($state)),
                TextColumn::make('specific_date')->label(__('Дата'))->date('d.m.Y'),
                TextColumn::make('start_time')->label(__('Начало')),
                TextColumn::make('end_time')->label(__('Окончание')),
                TextColumn::make('timezone')->label(__('Часовой пояс')),
                IconColumn::make('is_active')->label(__('Активен'))->boolean(),
            ])
            ->defaultSort('area_name')
            ->recordActions([EditAction::make()->label(__('Изменить'))]);
    }

    private static function weekdayLabel(?int $weekday): string
    {
        return [
            1 => __('Пн'),
            2 => __('Вт'),
            3 => __('Ср'),
            4 => __('Чт'),
            5 => __('Пт'),
            6 => __('Сб'),
            7 => __('Вс'),
        ][$weekday ?? 0] ?? '';
    }
}
