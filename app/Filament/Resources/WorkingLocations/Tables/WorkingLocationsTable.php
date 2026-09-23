<?php

namespace App\Filament\Resources\WorkingLocations\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WorkingLocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Название'))->searchable()->sortable(),
                TextColumn::make('address')->label(__('Адрес'))->wrap(),
                TextColumn::make('timezone')->label(__('Часовой пояс'))->sortable(),
                IconColumn::make('is_default_office')->label(__('Основная'))->boolean(),
                IconColumn::make('is_active')->label(__('Активна'))->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()->label(__('Изменить'))]);
    }
}
