<?php

namespace App\Filament\Resources\Services\Tables;

use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use InvalidArgumentException;

class ServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Название')->searchable()->sortable(),
                TextColumn::make('summary')->label('Описание')->limit(80),
                TextColumn::make('catalog_type')
                    ->label('Тип')
                    ->formatStateUsing(fn (CatalogItemType|string|null $state): string => match ($state instanceof CatalogItemType ? $state : CatalogItemType::tryFrom((string) $state)) {
                        CatalogItemType::PhysicalProduct => 'Физический товар',
                        CatalogItemType::OnlineProduct => 'Онлайн-товар',
                        default => 'Услуга',
                    }),
                TextColumn::make('category')->label('Категория')->placeholder('—')->sortable(),
                TextColumn::make('duration_minutes')->label('Длительность')->suffix(' мин.')->sortable(),
                TextColumn::make('price_minor')
                    ->label('Цена')
                    ->state(function (Service $record): string {
                        if ($record->price_minor === null || $record->price_currency === null) {
                            return '—';
                        }

                        try {
                            return Money::ofMinor($record->price_minor, $record->price_currency)->toDecimalString()
                                .' '.$record->price_currency;
                        } catch (InvalidArgumentException) {
                            return '—';
                        }
                    }),
                IconColumn::make('is_active')->label('Доступна')->boolean()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Доступна для записи'),
            ])
            ->emptyStateHeading('Услуг пока нет')
            ->emptyStateDescription('Добавьте консультации или процедуры, доступные для записи клиентов.')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
