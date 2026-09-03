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
                TextColumn::make('name')->label('Название')->searchable()->sortable(),
                TextColumn::make('address')->label('Адрес')->wrap(),
                TextColumn::make('timezone')->label('Часовой пояс')->sortable(),
                IconColumn::make('is_default_office')->label('Основная')->boolean(),
                IconColumn::make('is_active')->label('Активна')->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()->label('Изменить')]);
    }
}
