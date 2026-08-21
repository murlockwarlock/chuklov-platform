<?php

namespace App\Filament\Resources\AiPrompts\Schemas;

use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Models\AiPrompt;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AiPromptForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название промпта')
                    ->required()
                    ->maxLength(200),
                TextInput::make('key')
                    ->label('Ключ промпта')
                    ->helperText('Техническая идентичность промпта. После создания изменить нельзя.')
                    ->required()
                    ->maxLength(80)
                    ->regex('/^[a-z0-9_\-]+$/')
                    ->disabled(fn (?AiPrompt $record): bool => $record !== null)
                    ->dehydrated(true),
                Select::make('capability')
                    ->label('Назначение / Возможность')
                    ->options(collect(AiCapability::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                    ->required(),
                Textarea::make('description')
                    ->label('Описание')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }
}
