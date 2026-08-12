<?php

namespace App\Filament\Resources\ContentSections\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ContentSectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('section_key')
                    ->label('Section key')
                    ->required()
                    ->maxLength(64),
                Select::make('locale')
                    ->options([
                        'en' => 'English',
                        'ru' => 'Russian',
                    ])
                    ->required(),
                TextInput::make('title')
                    ->required()
                    ->maxLength(160),
                Textarea::make('body')
                    ->required()
                    ->maxLength(100000)
                    ->rows(12),
                KeyValue::make('media')
                    ->label('Media metadata')
                    ->keyLabel('Key')
                    ->valueLabel('Value'),
                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->minValue(0),
                Toggle::make('is_visible')
                    ->required()
                    ->default(true),
            ]);
    }
}
