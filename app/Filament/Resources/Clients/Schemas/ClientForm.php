<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Filament\Support\TimezoneOptions;
use App\Modules\Broadcasts\Domain\Enums\B2bSpecialistAnswer;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('expected_snapshot')->dehydrated()->nullable()->string(),
                Hidden::make('full_name'),
                TextInput::make('last_name')
                    ->label(__('Фамилия'))
                    ->required()
                    ->maxLength(100),
                TextInput::make('first_name')
                    ->label(__('Имя'))
                    ->required()
                    ->maxLength(100),
                TextInput::make('middle_name')->label(__('Отчество'))->maxLength(100),
                ToggleButtons::make('gender')
                    ->label(__('Пол'))
                    ->options(['MALE' => __('Мужской'), 'FEMALE' => __('Женский')])
                    ->inline(),
                DatePicker::make('birth_date')->label(__('Дата рождения'))->maxDate(now()),
                TextInput::make('country')->label(__('Страна'))->maxLength(100),
                TextInput::make('city')->label(__('Город'))->maxLength(100),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->maxLength(320),
                TextInput::make('phone')
                    ->label(__('Телефон'))
                    ->maxLength(32),
                Toggle::make('archived')
                    ->label(__('Архивный клиент'))
                    ->helperText(__('Архивирование сохраняет историю и добавляет клиента в категорию «Удалённые / архивные».'))
                    ->visibleOn('edit'),
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
