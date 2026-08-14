<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Filament\Support\ScheduleImpactPreview;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(160),
                Textarea::make('summary')
                    ->label('Краткое описание')
                    ->required()
                    ->maxLength(500)
                    ->rows(4),
                TextInput::make('name_ru')
                    ->label('Название на русском')
                    ->maxLength(160),
                TextInput::make('name_en')
                    ->label('Название на английском')
                    ->maxLength(160),
                Textarea::make('description_ru')
                    ->label('Описание на русском')
                    ->maxLength(10000)
                    ->rows(4),
                Textarea::make('description_en')
                    ->label('Описание на английском')
                    ->maxLength(10000)
                    ->rows(4),
                TextInput::make('category')
                    ->label('Категория')
                    ->maxLength(120),
                TextInput::make('duration_minutes')
                    ->label('Длительность (минуты)')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(65535),
                TextInput::make('buffer_minutes')
                    ->label('Пауза после визита (минуты)')
                    ->integer()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(65535),
                CheckboxList::make('formats')
                    ->options([
                        'office' => 'В клинике',
                        'home' => 'Выезд на дом',
                        'online' => 'Онлайн',
                    ])
                    ->label('Доступные форматы')
                    ->columns(3),
                TextInput::make('price_minor')
                    ->label('Цена')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(PHP_INT_MAX),
                TextInput::make('price_currency')
                    ->label('Валюта')
                    ->maxLength(3),
                TextInput::make('payment_policy')
                    ->label('Условия оплаты')
                    ->maxLength(64),
                Toggle::make('is_active')
                    ->label('Доступна для записи')
                    ->required()
                    ->default(true),
                Select::make('catalog_type')
                    ->label('Тип предложения')
                    ->options([
                        CatalogItemType::Service->value => 'Услуга',
                        CatalogItemType::PhysicalProduct->value => 'Физический товар',
                        CatalogItemType::OnlineProduct->value => 'Онлайн-товар',
                    ])
                    ->required()
                    ->default(CatalogItemType::Service->value),
                ...ScheduleImpactPreview::components(),
            ]);
    }
}
