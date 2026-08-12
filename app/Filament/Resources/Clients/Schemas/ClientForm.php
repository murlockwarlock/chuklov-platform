<?php

namespace App\Filament\Resources\Clients\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('full_name')
                    ->label('Full name')
                    ->required()
                    ->maxLength(160),
                TextInput::make('email')
                    ->email()
                    ->maxLength(320),
                TextInput::make('phone')
                    ->maxLength(32),
                Select::make('language')
                    ->options([
                        'en' => 'English',
                        'ru' => 'Russian',
                    ])
                    ->required(),
                TextInput::make('timezone')
                    ->required()
                    ->maxLength(64),
                TextInput::make('lead_source')
                    ->maxLength(120),
                TextInput::make('referral_code')
                    ->maxLength(160),
            ]);
    }
}
