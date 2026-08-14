<?php

namespace App\Filament\Resources\ContentSections\Schemas;

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
                Select::make('section_key')
                    ->label('Раздел')
                    ->options([
                        'author' => 'Об академии',
                        'method' => 'Методика',
                        'b2b' => 'Для бизнеса',
                        'partner' => 'Партнёрам',
                        'hidden' => 'Скрытый раздел',
                    ])
                    ->searchable()
                    ->required(),
                Select::make('locale')
                    ->options([
                        'en' => 'Английский',
                        'ru' => 'Русский',
                    ])
                    ->label('Язык')
                    ->required(),
                TextInput::make('title')
                    ->label('Название')
                    ->required()
                    ->maxLength(160),
                Textarea::make('body')
                    ->label('Текст')
                    ->required()
                    ->maxLength(100000)
                    ->rows(12),
                TextInput::make('media.image')
                    ->label('Изображение')
                    ->url()
                    ->maxLength(2000),
                TextInput::make('media.alt')
                    ->label('Описание изображения')
                    ->maxLength(255),
                TextInput::make('sort_order')
                    ->label('Порядок показа')
                    ->integer()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(PHP_INT_MAX),
                Toggle::make('is_visible')
                    ->label('Показывать')
                    ->required()
                    ->default(true),
            ]);
    }
}
