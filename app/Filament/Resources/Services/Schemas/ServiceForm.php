<?php

namespace App\Filament\Resources\Services\Schemas;

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
                    ->required()
                    ->maxLength(160),
                Textarea::make('summary')
                    ->required()
                    ->maxLength(500)
                    ->rows(4),
                Toggle::make('is_active')
                    ->required()
                    ->default(true),
            ]);
    }
}
