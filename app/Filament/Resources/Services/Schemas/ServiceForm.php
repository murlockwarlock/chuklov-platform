<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Modules\Services\Domain\Enums\CatalogItemType;
use Filament\Forms\Components\Checkbox;
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
                    ->label('Fallback name')
                    ->required()
                    ->maxLength(160),
                Textarea::make('summary')
                    ->label('Fallback summary')
                    ->required()
                    ->maxLength(500)
                    ->rows(4),
                TextInput::make('name_ru')
                    ->label('Name (RU)')
                    ->maxLength(160),
                TextInput::make('name_en')
                    ->label('Name (EN)')
                    ->maxLength(160),
                Textarea::make('description_ru')
                    ->label('Description (RU)')
                    ->maxLength(10000)
                    ->rows(4),
                Textarea::make('description_en')
                    ->label('Description (EN)')
                    ->maxLength(10000)
                    ->rows(4),
                TextInput::make('category')
                    ->maxLength(120),
                TextInput::make('duration_minutes')
                    ->label('Duration (minutes)')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(65535),
                TextInput::make('buffer_minutes')
                    ->label('Buffer (minutes)')
                    ->integer()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(65535),
                CheckboxList::make('formats')
                    ->options([
                        'office' => 'Office',
                        'home' => 'Home visit',
                        'online' => 'Online',
                    ])
                    ->columns(3),
                TextInput::make('price_minor')
                    ->label('Price (minor currency units)')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(PHP_INT_MAX),
                TextInput::make('price_currency')
                    ->label('Currency')
                    ->maxLength(3),
                TextInput::make('payment_policy')
                    ->label('Payment policy')
                    ->maxLength(64),
                Toggle::make('is_active')
                    ->required()
                    ->default(true),
                Select::make('catalog_type')
                    ->label('Catalog item type')
                    ->options([
                        CatalogItemType::Service->value => 'Service',
                        CatalogItemType::PhysicalProduct->value => 'Physical product',
                        CatalogItemType::OnlineProduct->value => 'Online product',
                    ])
                    ->required()
                    ->default(CatalogItemType::Service->value),
                Checkbox::make('acknowledge_impact')
                    ->label('Acknowledge impact on future bookings if shown')
                    ->default(false),
            ]);
    }
}
