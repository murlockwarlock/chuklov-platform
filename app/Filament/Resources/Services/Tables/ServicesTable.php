<?php

namespace App\Filament\Resources\Services\Tables;

use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('summary')->limit(80),
                TextColumn::make('catalog_type')
                    ->label('Type')
                    ->formatStateUsing(fn (CatalogItemType|string|null $state): string => match ($state instanceof CatalogItemType ? $state : CatalogItemType::tryFrom((string) $state)) {
                        CatalogItemType::PhysicalProduct => 'Physical product',
                        CatalogItemType::OnlineProduct => 'Online product',
                        default => 'Service',
                    }),
                TextColumn::make('category')->placeholder('—')->sortable(),
                TextColumn::make('duration_minutes')->label('Duration')->suffix(' min')->sortable(),
                TextColumn::make('price_minor')
                    ->label('Price')
                    ->state(fn (Service $record): string => $record->price_minor === null
                        ? '—'
                        : $record->price_minor.' '.($record->price_currency ?? '')),
                IconColumn::make('is_active')->boolean()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
