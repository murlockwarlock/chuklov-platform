<?php

namespace App\Filament\Resources\AiPrompts\Schemas;

use App\Filament\Support\CrmLabel;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Models\AiPrompt;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AiPromptForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Основная информация'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Название'))
                            ->required()
                            ->maxLength(200),
                        Select::make('capability')
                            ->label(__('Для чего используется'))
                            ->options(collect(AiCapability::cases())->mapWithKeys(fn (AiCapability $capability): array => [$capability->value => CrmLabel::enum($capability)]))
                            ->required(),
                        Textarea::make('description')
                            ->label(__('Описание'))
                            ->helperText(__('Коротко опишите, в каких ситуациях этот промпт помогает специалисту.'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make(__('Дополнительные параметры'))
                    ->description(__('Если внутреннее имя не указать, оно создастся автоматически и не изменится после сохранения.'))
                    ->collapsed()
                    ->schema([
                        TextInput::make('key')
                            ->label(__('Внутреннее имя'))
                            ->helperText(__('Оставьте пустым для автоматического создания. Ручной ввод нужен только для существующих интеграций.'))
                            ->maxLength(80)
                            ->regex('/^[a-z0-9_\-]+$/')
                            ->disabled(fn (?AiPrompt $record): bool => $record !== null)
                            ->dehydrated(true),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
