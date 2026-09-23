<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Filament\Support\TimezoneOptions;
use App\Modules\Broadcasts\Domain\Enums\B2bSpecialistAnswer;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Forms\Components\Hidden;
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
                Hidden::make('expected_snapshot')->dehydrated()->nullable()->string(),
                TextInput::make('full_name')
                    ->label(__('Имя и фамилия'))
                    ->required()
                    ->maxLength(160),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->maxLength(320),
                TextInput::make('phone')
                    ->label(__('Телефон'))
                    ->maxLength(32),
                Select::make('language')
                    ->options([
                        'en' => __('Английский'),
                        'ru' => __('Русский'),
                    ])
                    ->label(__('Язык'))
                    ->required(),
                Select::make('timezone')
                    ->label(__('Часовой пояс'))
                    ->options(fn (Get $get): array => TimezoneOptions::options(
                        current: $get('timezone'),
                        organization: app(OrganizationContext::class)->organization()->defaultTimezone(),
                    ))
                    ->searchable()
                    ->required()
                    ->helperText(__('Выберите город, по которому показывать время клиенту.')),
                TextInput::make('b2b_role')
                    ->label(__('B2B-роль'))
                    ->maxLength(80),
                Select::make('b2b_specialist_answer')
                    ->label(__('B2B-сегмент'))
                    ->options([
                        B2bSpecialistAnswer::Yes->value => __('#Массажист_B2B'),
                        B2bSpecialistAnswer::No->value => __('Не специалист'),
                    ])
                    ->nullable(),
                TagsInput::make('broadcast_tags')
                    ->label(__('Метки для рассылок'))
                    ->nestedRecursiveRules(['string', 'max:80'])
                    ->columnSpanFull(),
            ]);
    }
}
