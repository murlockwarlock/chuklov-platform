<?php

namespace App\Filament\Resources\Services\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('summary')->limit(80),
                IconColumn::make('is_active')->boolean()->sortable(),
            ])
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
