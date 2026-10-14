<?php

namespace App\Filament\Resources\Clients\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;

final class PatientIdentityForm
{
    /** @return array<int, mixed> */
    public static function components(): array
    {
        return [
            TextInput::make('last_name')->label(__('Фамилия'))->required()->maxLength(100),
            TextInput::make('first_name')->label(__('Имя'))->required()->maxLength(100),
            TextInput::make('middle_name')->label(__('Отчество'))->nullable()->maxLength(100),
            Select::make('gender')->label(__('Пол'))->options(['MALE' => __('Мужской'), 'FEMALE' => __('Женский')])->required(),
            DatePicker::make('birth_date')->label(__('Дата рождения'))->required()->maxDate(today())->native(),
            Select::make('relationship')->label(__('Родство'))->options([
                'SPOUSE' => __('Супруг / супруга'), 'CHILD' => __('Ребёнок'), 'PARENT' => __('Родитель'),
            ])->required(),
            Hidden::make('creation_key')->default(fn (): string => (string) Str::uuid())->required(),
        ];
    }
}
