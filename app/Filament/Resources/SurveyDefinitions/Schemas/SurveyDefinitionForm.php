<?php

namespace App\Filament\Resources\SurveyDefinitions\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SurveyDefinitionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Основное')->schema([
                TextInput::make('definition_key')->label('Постоянный ключ')->required()->maxLength(120)->disabledOn('edit'),
                TextInput::make('title')->label('Название')->required()->maxLength(200),
                TextInput::make('title_en')->label('Название на английском')->maxLength(200),
                Textarea::make('description')->label('Краткое описание')->maxLength(2000)->columnSpanFull(),
                Textarea::make('description_en')->label('Описание на английском')->maxLength(2000)->columnSpanFull(),
                TextInput::make('metric_schema_key')->label('Ключ совместимости показателей')->maxLength(120),
                TextInput::make('source_reference')->label('Источник определения')->maxLength(500),
                Toggle::make('is_available')->label('Доступен клиентам после публикации')->default(true),
            ])->columns(2),
            Section::make('Разделы и вопросы')->schema([
                Repeater::make('sections')->label('Разделы')->required()->minItems(1)->schema([
                    TextInput::make('key')->label('Ключ раздела')->required(),
                    TextInput::make('title')->label('Название раздела')->required(),
                    TextInput::make('title_en')->label('Название раздела на английском'),
                    Repeater::make('questions')->label('Вопросы')->required()->minItems(1)->schema([
                        TextInput::make('key')->label('Постоянный ключ вопроса')->required(),
                        TextInput::make('label')->label('Текст вопроса')->required(),
                        TextInput::make('label_en')->label('Текст вопроса на английском'),
                        Select::make('type')->label('Тип ответа')->options([
                            'single_choice' => 'Один вариант', 'multiple_choice' => 'Несколько вариантов', 'boolean' => 'Да / нет',
                            'integer' => 'Целое число', 'number' => 'Число', 'short_text' => 'Короткий текст', 'long_text' => 'Развёрнутый текст',
                        ])->required()->live(),
                        Toggle::make('required')->label('Обязательный'),
                        Repeater::make('options')->label('Варианты ответа')->schema([
                            TextInput::make('value')->label('Ключ варианта')->required(),
                            TextInput::make('label')->label('Подпись')->required(),
                            TextInput::make('label_en')->label('Подпись на английском'),
                        ])->columns(2)->visible(fn ($get): bool => in_array($get('type'), ['single_choice', 'multiple_choice'], true)),
                        TextInput::make('condition_question_key')->label('Показывать после вопроса'),
                        Select::make('condition_operator')->label('Условие показа')->options([
                            'equals' => 'Равно', 'not_equals' => 'Не равно', 'in' => 'В списке', 'not_in' => 'Не в списке',
                            'answered' => 'Есть ответ', 'greater_than' => 'Больше', 'less_than' => 'Меньше',
                        ]),
                        TagsInput::make('condition_values')->label('Значения условия')->visible(fn ($get): bool => in_array($get('condition_operator'), ['in', 'not_in'], true)),
                        TextInput::make('condition_value')->label('Значение условия')->visible(fn ($get): bool => ! in_array($get('condition_operator'), ['in', 'not_in', 'answered'], true)),
                    ])->columns(2)->columnSpanFull(),
                ])->columnSpanFull(),
            ]),
            Section::make('Подсчёт результата')->schema([
                Repeater::make('metrics')->label('Показатели')->required()->schema([
                    TextInput::make('key')->label('Ключ показателя')->required(),
                    TextInput::make('label')->label('Название показателя')->required(),
                    TextInput::make('label_en')->label('Название показателя на английском'),
                ])->columns(2),
                Repeater::make('rules')->label('Правила подсчёта')->schema([
                    TextInput::make('question_key')->label('Ключ вопроса')->required(),
                    TextInput::make('metric_key')->label('Ключ показателя')->required(),
                    Select::make('operator')->label('Способ подсчёта')->options([
                        'value_map' => 'Баллы по выбранному ответу', 'selected_sum' => 'Сумма выбранных вариантов', 'numeric_value' => 'Числовой ответ',
                    ])->required()->live(),
                    Repeater::make('points')->label('Таблица баллов')->schema([
                        TextInput::make('value')->label('Ключ ответа')->required(),
                        TextInput::make('points')->label('Баллы')->numeric()->required(),
                    ])->columns(2)->visible(fn ($get): bool => in_array($get('operator'), ['value_map', 'selected_sum'], true)),
                    TextInput::make('multiplier')->label('Множитель')->numeric()->default(1)->visible(fn ($get): bool => $get('operator') === 'numeric_value'),
                ])->columns(2),
                Repeater::make('thresholds')->label('Пороговые отметки')->schema([
                    TextInput::make('metric_key')->label('Ключ показателя')->required(),
                    TextInput::make('min')->label('Минимум')->numeric(),
                    TextInput::make('max')->label('Максимум')->numeric(),
                    TextInput::make('tag')->label('Ключ отметки')->required(),
                    TextInput::make('label')->label('Текст результата')->required(),
                    TextInput::make('label_en')->label('Текст результата на английском'),
                ])->columns(2),
                TagsInput::make('comparison_metric_keys')->label('Сравниваемые показатели')->helperText('Повторные тесты сравниваются только при одинаковом ключе совместимости.'),
            ]),
        ]);
    }
}
