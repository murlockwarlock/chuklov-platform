<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Filament\Support\TimezoneOptions;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('full_name')
                    ->label('Имя и фамилия')
                    ->required()
                    ->maxLength(160),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->maxLength(320),
                TextInput::make('phone')
                    ->label('Телефон')
                    ->maxLength(32),
                Select::make('language')
                    ->options([
                        'en' => 'Английский',
                        'ru' => 'Русский',
                    ])
                    ->label('Язык')
                    ->required(),
                Select::make('timezone')
                    ->label('Часовой пояс')
                    ->options(fn (Get $get): array => TimezoneOptions::options(
                        current: $get('timezone'),
                        organization: app(OrganizationContext::class)->organization()->defaultTimezone(),
                    ))
                    ->searchable()
                    ->required()
                    ->helperText('Выберите город, по которому показывать время клиенту.'),
                TextInput::make('b2b_role')
                    ->label('B2B-роль')
                    ->maxLength(80),
                TagsInput::make('broadcast_tags')
                    ->label('Метки для рассылок')
                    ->nestedRecursiveRules(['string', 'max:80'])
                    ->columnSpanFull(),
            ]);
    }
}
