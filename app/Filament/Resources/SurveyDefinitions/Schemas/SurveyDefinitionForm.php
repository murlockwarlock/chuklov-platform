<?php

namespace App\Filament\Resources\SurveyDefinitions\Schemas;

use App\Filament\Support\SurveyDefinitionFormMapper;
use App\Filament\Support\SurveyDefinitionFormOptions;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class SurveyDefinitionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Основное')->schema([
                TextInput::make('title')->label('Название')->required()->maxLength(200),
                TextInput::make('title_en')->label('Название на английском')->maxLength(200),
                Textarea::make('description')->label('Краткое описание')->maxLength(2000)->columnSpanFull(),
                Textarea::make('description_en')->label('Описание на английском')->maxLength(2000)->columnSpanFull(),
                Toggle::make('is_available')->label('Доступен клиентам после публикации')->default(true),
                Hidden::make('start_new_metric_scale')->default(false),
            ])->columns(2),
            Section::make('Вопросы')->schema([
                Repeater::make('sections')
                    ->label('Разделы')
                    ->required()
                    ->minItems(1)
                    ->reorderable()
                    ->cloneable(false)
                    ->itemNumbers()
                    ->addActionLabel('Добавить раздел')
                    ->schema([
                        Hidden::make('key')->default(fn (): string => SurveyDefinitionFormMapper::newIdentity()),
                        TextInput::make('title')->label('Название раздела')->required(),
                        TextInput::make('title_en')->label('Название раздела на английском'),
                        Repeater::make('questions')
                            ->label('Вопросы')
                            ->required()
                            ->minItems(1)
                            ->reorderable()
                            ->cloneable(false)
                            ->itemNumbers()
                            ->addActionLabel('Добавить вопрос')
                            ->schema([
                                Hidden::make('key')->default(fn (): string => SurveyDefinitionFormMapper::newIdentity()),
                                Hidden::make('condition_legacy'),
                                TextInput::make('label')->label('Текст вопроса')->required(),
                                TextInput::make('label_en')->label('Текст вопроса на английском'),
                                Select::make('type')->label('Тип ответа')->options([
                                    'single_choice' => 'Один вариант',
                                    'multiple_choice' => 'Несколько вариантов',
                                    'boolean' => 'Да / нет',
                                    'integer' => 'Целое число',
                                    'number' => 'Число',
                                    'short_text' => 'Короткий текст',
                                    'long_text' => 'Развёрнутый текст',
                                ])->required()->live(),
                                Toggle::make('required')->label('Обязательный'),
                                Repeater::make('options')
                                    ->label('Варианты ответа')
                                    ->required()
                                    ->minItems(1)
                                    ->reorderable()
                                    ->cloneable(false)
                                    ->addActionLabel('Добавить вариант')
                                    ->schema([
                                        Hidden::make('value')->default(fn (): string => SurveyDefinitionFormMapper::newIdentity()),
                                        TextInput::make('label')->label('Текст варианта')->required(),
                                        TextInput::make('label_en')->label('Текст варианта на английском'),
                                    ])->columns(2)
                                    ->visible(fn (Get $get): bool => in_array($get('type'), ['single_choice', 'multiple_choice'], true)),
                                Placeholder::make('condition_legacy_notice')
                                    ->label('Условие показа')
                                    ->content('Сохранённое условие нельзя безопасно редактировать в этом конструкторе. Оно будет сохранено без изменений.')
                                    ->visible(fn (Get $get): bool => is_array($get('condition_legacy')))
                                    ->columnSpanFull(),
                                Select::make('condition_question_key')
                                    ->label('Показывать после вопроса')
                                    ->placeholder('Без условия')
                                    ->options(fn (Get $get): array => SurveyDefinitionFormOptions::previousQuestionOptions(
                                        self::sections($get),
                                        $get('key'),
                                        $get('condition_question_key'),
                                    ))
                                    ->helperText(fn (Get $get): ?string => SurveyDefinitionFormOptions::conditionHelp(
                                        self::sections($get),
                                        $get('key'),
                                        $get('condition_question_key'),
                                    ))
                                    ->disabled(fn (Get $get): bool => self::hasLegacyCondition($get))
                                    ->searchable()
                                    ->live(),
                                Select::make('condition_operator')
                                    ->label('Условие показа')
                                    ->options(fn (Get $get): array => SurveyDefinitionFormOptions::conditionOperators(
                                        SurveyDefinitionFormOptions::questionType(self::sections($get), $get('condition_question_key')),
                                        $get('condition_operator'),
                                    ))
                                    ->disabled(fn (Get $get): bool => self::hasLegacyCondition($get))
                                    ->visible(fn (Get $get): bool => filled($get('condition_question_key')) || filled($get('condition_operator')))
                                    ->live(),
                                Select::make('condition_option_value')
                                    ->label('Вариант ответа')
                                    ->options(fn (Get $get): array => SurveyDefinitionFormOptions::optionOptions(
                                        self::sections($get),
                                        $get('condition_question_key'),
                                        $get('condition_option_value'),
                                    ))
                                    ->visible(fn (Get $get): bool => self::conditionType($get) === 'single_choice'
                                        && in_array($get('condition_operator'), ['equals', 'not_equals'], true))
                                    ->disabled(fn (Get $get): bool => self::hasLegacyCondition($get))
                                    ->searchable(),
                                Select::make('condition_values')
                                    ->label('Варианты ответа')
                                    ->options(fn (Get $get): array => SurveyDefinitionFormOptions::optionOptions(
                                        self::sections($get),
                                        $get('condition_question_key'),
                                        $get('condition_values'),
                                    ))
                                    ->multiple()
                                    ->visible(fn (Get $get): bool => self::conditionType($get) === 'single_choice'
                                        && in_array($get('condition_operator'), ['in', 'not_in'], true))
                                    ->disabled(fn (Get $get): bool => self::hasLegacyCondition($get))
                                    ->searchable(),
                                Select::make('condition_boolean_value')
                                    ->label('Ответ')
                                    ->options(['true' => 'Да', 'false' => 'Нет'])
                                    ->visible(fn (Get $get): bool => self::conditionType($get) === 'boolean'
                                        && in_array($get('condition_operator'), ['equals', 'not_equals'], true))
                                    ->disabled(fn (Get $get): bool => self::hasLegacyCondition($get)),
                                TextInput::make('condition_value')
                                    ->label('Значение')
                                    ->numeric(fn (Get $get): bool => in_array(self::conditionType($get), ['integer', 'number'], true))
                                    ->rules([
                                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                            if (self::hasLegacyCondition($get)
                                                || self::conditionType($get) !== 'integer'
                                                || ! in_array($get('condition_operator'), ['equals', 'not_equals', 'greater_than', 'less_than'], true)
                                                || $value === null
                                                || $value === ''
                                                || SurveyDefinitionFormMapper::isWholeIntegerInput($value)) {
                                                return;
                                            }

                                            $fail('Укажите целое число.');
                                        },
                                    ])
                                    ->visible(fn (Get $get): bool => in_array(self::conditionType($get), ['integer', 'number', 'short_text', 'long_text'], true)
                                        && in_array($get('condition_operator'), ['equals', 'not_equals', 'greater_than', 'less_than'], true))
                                    ->disabled(fn (Get $get): bool => self::hasLegacyCondition($get)),
                            ])->columns(2)->columnSpanFull(),
                    ])->columnSpanFull(),
            ]),
            Section::make('Подсчёт результата')->schema([
                Hidden::make('legacy_scoring'),
                Placeholder::make('legacy_scoring_notice')
                    ->label('Подсчёт результата')
                    ->content('Сохранённая конфигурация содержит правила, которые нельзя безопасно редактировать в этом конструкторе. Она будет сохранена без изменений.')
                    ->visible(fn (Get $get): bool => is_array($get('legacy_scoring')))
                    ->columnSpanFull(),
                Repeater::make('metrics')
                    ->label('Показатели')
                    ->required()
                    ->minItems(1)
                    ->reorderable()
                    ->cloneable(false)
                    ->addActionLabel('Добавить показатель')
                    ->schema([
                        Hidden::make('key')->default(fn (): string => SurveyDefinitionFormMapper::newIdentity()),
                        TextInput::make('label')->label('Название показателя')->required(),
                        TextInput::make('label_en')->label('Название показателя на английском'),
                    ])->columns(2)
                    ->disabled(fn (Get $get): bool => self::hasLegacyScoring($get)),
                Repeater::make('rules')
                    ->label('Правила подсчёта')
                    ->reorderable()
                    ->cloneable(false)
                    ->addActionLabel('Добавить правило')
                    ->schema([
                        Select::make('question_key')
                            ->label('Вопрос')
                            ->options(fn (Get $get): array => SurveyDefinitionFormOptions::allQuestionOptions(
                                self::sections($get),
                                $get('question_key'),
                            ))
                            ->required()
                            ->searchable()
                            ->live(),
                        Select::make('metric_key')
                            ->label('Показатель')
                            ->options(fn (Get $get): array => SurveyDefinitionFormOptions::metricOptions(
                                self::metrics($get),
                                $get('metric_key'),
                            ))
                            ->required()
                            ->searchable(),
                        Select::make('operator')
                            ->label('Способ подсчёта')
                            ->options(fn (Get $get): array => SurveyDefinitionFormOptions::scoringOperators(
                                SurveyDefinitionFormOptions::questionType(self::sections($get), $get('question_key')),
                                $get('operator'),
                            ))
                            ->required()
                            ->live(),
                        Repeater::make('points')
                            ->label('Баллы за варианты')
                            ->reorderable()
                            ->cloneable(false)
                            ->addActionLabel('Добавить вариант')
                            ->schema([
                                Select::make('value')
                                    ->label('Вариант ответа')
                                    ->options(fn (Get $get): array => SurveyDefinitionFormOptions::optionOptions(
                                        self::sections($get),
                                        $get('../../question_key'),
                                        $get('value'),
                                    ))
                                    ->required()
                                    ->searchable(),
                                TextInput::make('points')->label('Баллы')->numeric()->required(),
                            ])->columns(2)
                            ->visible(fn (Get $get): bool => in_array($get('operator'), ['value_map', 'selected_sum'], true)),
                        TextInput::make('multiplier')
                            ->label('Множитель')
                            ->numeric()
                            ->default(1)
                            ->visible(fn (Get $get): bool => $get('operator') === 'numeric_value'),
                    ])->columns(2)
                    ->disabled(fn (Get $get): bool => self::hasLegacyScoring($get)),
                Repeater::make('thresholds')
                    ->label('Пороговые результаты')
                    ->reorderable()
                    ->cloneable(false)
                    ->addActionLabel('Добавить результат')
                    ->schema([
                        Select::make('metric_key')
                            ->label('Показатель')
                            ->options(fn (Get $get): array => SurveyDefinitionFormOptions::metricOptions(
                                self::metrics($get),
                                $get('metric_key'),
                            ))
                            ->required()
                            ->searchable(),
                        TextInput::make('min')->label('Минимум')->numeric(),
                        TextInput::make('max')->label('Максимум')->numeric(),
                        Hidden::make('tag')->default(fn (): string => SurveyDefinitionFormMapper::newIdentity()),
                        TextInput::make('label')->label('Текст результата')->required(),
                        TextInput::make('label_en')->label('Текст результата на английском'),
                    ])->columns(2)
                    ->disabled(fn (Get $get): bool => self::hasLegacyScoring($get)),
                Select::make('comparison_metric_keys')
                    ->label('Показатели для сравнения повторных результатов')
                    ->options(fn (Get $get): array => SurveyDefinitionFormOptions::metricOptions(
                        self::metrics($get),
                        $get('comparison_metric_keys'),
                    ))
                    ->multiple()
                    ->searchable()
                    ->helperText('Выберите показатели, которые нужно сравнивать в повторных результатах.')
                    ->disabled(fn (Get $get): bool => self::hasLegacyScoring($get))
                    ->columnSpanFull(),
            ]),
        ]);
    }

    /** @return array<int|string, mixed> */
    private static function sections(Get $get): array
    {
        $sections = $get('/data.sections');

        return is_array($sections) ? $sections : [];
    }

    /** @return array<int|string, mixed> */
    private static function metrics(Get $get): array
    {
        $metrics = $get('/data.metrics');

        return is_array($metrics) ? $metrics : [];
    }

    private static function conditionType(Get $get): ?string
    {
        return SurveyDefinitionFormOptions::questionType(self::sections($get), $get('condition_question_key'));
    }

    private static function hasLegacyScoring(Get $get): bool
    {
        return is_array($get('/data.legacy_scoring'));
    }

    private static function hasLegacyCondition(Get $get): bool
    {
        return is_array($get('condition_legacy'));
    }
}
