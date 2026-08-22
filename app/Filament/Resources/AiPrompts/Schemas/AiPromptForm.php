<?php

namespace App\Filament\Resources\AiPrompts\Schemas;

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
                Section::make('Основная информация')
                    ->schema([
                        TextInput::make('name')
                            ->label('Название')
                            ->required()
                            ->maxLength(200),
                        Select::make('capability')
                            ->label('Для чего используется')
                            ->options(collect(AiCapability::cases())->mapWithKeys(fn (AiCapability $capability): array => [$capability->value => $capability->label()]))
                            ->required(),
                        Textarea::make('description')
                            ->label('Описание')
                            ->helperText('Коротко опишите, в каких ситуациях этот промпт помогает специалисту.')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Технические детали')
                    ->description('Техническое имя создаётся автоматически из названия, если его не указать. После создания оно неизменно.')
                    ->collapsed()
                    ->schema([
                        TextInput::make('key')
                            ->label('Техническое имя')
                            ->helperText('Оставьте пустым для автоматического создания. Ручной ввод нужен только для существующих интеграций.')
                            ->maxLength(80)
                            ->regex('/^[a-z0-9_\-]+$/')
                            ->disabled(fn (?AiPrompt $record): bool => $record !== null)
                            ->dehydrated(true),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
