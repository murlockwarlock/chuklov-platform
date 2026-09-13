<?php

namespace App\Filament\Resources\SurveyDefinitions\Schemas;

use App\Filament\Support\SurveyDefinitionFormMapper;
use App\Filament\Support\SurveyDefinitionFormOptions;
use App\Filament\Support\SurveyDefinitionScoringFormMapper;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

final class SurveyDefinitionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Опросник')
                ->tabs([
                    Tab::make('Основное')->schema(self::mainSchema()),
                    Tab::make('Вопросы')->schema(self::questionsSchema()),
                    Tab::make('Подсчёт результата')->schema(self::scoringSchema()),
                    Tab::make('Результат для клиента')->schema(self::resultSchema()),
                    Tab::make('Публикация / версия')->schema(self::publicationSchema()),
                ])
                ->livewireProperty('surveyBuilderTab')
                ->columnSpanFull(),
        ]);
    }

    /** @return array<int, mixed> */
    private static function mainSchema(): array
    {
        return [
            Section::make('Основное')->schema([
                TextInput::make('title')->label('Название')->required()->maxLength(200),
                TextInput::make('title_en')->label('Название на английском')->maxLength(200),
                Textarea::make('description')->label('Краткое описание')->maxLength(2000)->columnSpanFull(),
                Textarea::make('description_en')->label('Описание на английском')->maxLength(2000)->columnSpanFull(),
                Toggle::make('is_available')->label('Доступен клиентам после публикации')->default(true),
                Hidden::make('start_new_metric_scale')->default(false),
                Hidden::make('expected_snapshot')->dehydrated()->nullable()->string(),
            ])->columns(2)->columnSpanFull(),
            Section::make('Статус материала')->schema([
                Hidden::make('source')->default('platform_default')->dehydrated(false),
                Hidden::make('approval_status')->default('draft')->dehydrated(false),
                Hidden::make('methodology')->dehydrated(false),
                Placeholder::make('provenance')
                    ->label('Происхождение')
                    ->content(fn (Get $get): string => self::provenanceLabel($get)),
                Placeholder::make('methodology_display')
                    ->label('Тип материала')
                    ->content(fn (Get $get): string => self::methodologyLabel($get)),
            ])->columns(2)->columnSpanFull(),
        ];
    }

    /** @return array<int, mixed> */
    private static function questionsSchema(): array
    {
        return [
            Section::make('Вопросы и порядок показа')->schema([
                Repeater::make('sections')
                    ->label('Разделы')
                    ->required()
                    ->minItems(1)
                    ->reorderable()
                    ->cloneable(false)
                    ->collapsed()
                    ->itemNumbers()
                    ->itemLabel(fn (array $state): string => self::sectionSummary($state))
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
                            ->collapsed()
                            ->itemNumbers()
                            ->itemLabel(fn (array $state): string => self::questionSummary($state))
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
                                    ->collapsed()
                                    ->itemLabel(fn (array $state): string => self::text($state['label'] ?? null, 'Новый вариант'))
                                    ->addActionLabel('Добавить вариант')
                                    ->schema([
                                        Hidden::make('value')->default(fn (): string => SurveyDefinitionFormMapper::newIdentity()),
                                        TextInput::make('label')->label('Текст варианта')->required(),
                                        TextInput::make('label_en')->label('Текст варианта на английском'),
                                    ])->columns(2)
                                    ->visible(fn (Get $get): bool => in_array($get('type'), ['single_choice', 'multiple_choice'], true)),
                                Placeholder::make('condition_legacy_notice')
                                    ->label('Условие показа')
                                    ->content('Сохранённое условие показа нельзя безопасно изменить. Оно будет сохранено без изменений.')
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
            ])->columnSpanFull(),
        ];
    }

    /** @return array<int, mixed> */
    private static function scoringSchema(): array
    {
        return [
            Hidden::make('legacy_scoring'),
            Section::make('Шкала ответов и показатели')->schema([
                Placeholder::make('legacy_scoring_notice')
                    ->label('Подсчёт результата')
                    ->content('Этот тест содержит расширенные правила, которые текущий редактор пока не поддерживает. Данные сохранены без изменений.')
                    ->visible(fn (Get $get): bool => self::hasLegacyScoring($get))
                    ->columnSpanFull(),
                Placeholder::make('answer_scale_notice')
                    ->label('Шкала баллов')
                    ->content('Баллы задаются в правилах ниже для каждого вопроса и показателя. Общая шкала сохраняется автоматически только как совместимое представление этих правил.')
                    ->visible(fn (Get $get): bool => ! self::hasLegacyScoring($get))
                    ->columnSpanFull(),
                Repeater::make('metrics')
                    ->label('Показатели')
                    ->required(fn (Get $get): bool => ! self::hasLegacyScoring($get))
                    ->minItems(fn (Get $get): int => self::hasLegacyScoring($get) ? 0 : 1)
                    ->reorderable()
                    ->cloneable(false)
                    ->collapsed()
                    ->itemLabel(fn (array $state): string => self::text($state['label'] ?? null, 'Новый показатель'))
                    ->addActionLabel('Добавить показатель')
                    ->schema([
                        Hidden::make('key')->default(fn (): string => SurveyDefinitionFormMapper::newIdentity()),
                        TextInput::make('label')->label('Название показателя')->required(),
                        TextInput::make('label_en')->label('Название показателя на английском'),
                        TextInput::make('max_value')
                            ->label('Максимальный результат')
                            ->numeric()
                            ->helperText('Для вопросов с вариантами рассчитывается по правилам.')
                            ->disabled(fn (Get $get): bool => self::metricMaxIsDerived($get)),
                        Select::make('normalization')
                            ->label('Шкала результата')
                            ->options(fn (Get $get): array => SurveyDefinitionFormOptions::normalizationOptions($get('normalization')))
                            ->searchable(),
                        Placeholder::make('question_membership_notice')
                            ->label('Вопросы в показателе')
                            ->content('Связь вопроса с показателем задаётся в правилах подсчёта ниже.')
                            ->columnSpanFull(),
                    ])->columns(2)
                    ->disabled(fn (Get $get): bool => self::hasLegacyScoring($get)),
            ])->columns(2)->columnSpanFull(),
            Section::make('Правила подсчёта')->schema([
                Repeater::make('rules')
                    ->label('Правила подсчёта')
                    ->reorderable()
                    ->cloneable(false)
                    ->collapsed()
                    ->itemLabel(fn (array $state): string => self::ruleSummary($state))
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
                            ->collapsed()
                            ->itemLabel(fn (array $state): string => filled($state['value'] ?? null) ? 'Баллы за выбранный вариант' : 'Новая настройка баллов')
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
                                    ->searchable()
                                    ->validationMessages(['distinct' => 'Варианты ответа в одном правиле должны быть уникальными.'])
                                    ->distinct(),
                                TextInput::make('points')->label('Баллы')->numeric()->required(),
                            ])->columns(2)
                            ->visible(fn (Get $get): bool => in_array($get('operator'), ['value_map', 'selected_sum'], true)),
                        TextInput::make('multiplier')
                            ->label('Коэффициент для числового ответа')
                            ->numeric()
                            ->default(1)
                            ->visible(fn (Get $get): bool => $get('operator') === 'numeric_value'),
                    ])->columns(2)
                    ->disabled(fn (Get $get): bool => self::hasLegacyScoring($get)),
            ])->columnSpanFull(),
            Section::make('Сравнение динамики')->schema([
                Select::make('comparison_operator')
                    ->label('Как сравнивать повторные результаты')
                    ->options(fn (Get $get): array => SurveyDefinitionFormOptions::comparisonOperatorOptions($get('comparison_operator')))
                    ->searchable()
                    ->disabled(fn (Get $get): bool => self::hasLegacyScoring($get)),
                Select::make('comparison_basis')
                    ->label('Основа сравнения')
                    ->options(fn (Get $get): array => SurveyDefinitionFormOptions::comparisonBasisOptions($get('comparison_basis')))
                    ->searchable()
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
            ])->columns(2)->columnSpanFull(),
        ];
    }

    /** @return array<int, mixed> */
    private static function resultSchema(): array
    {
        return [
            Section::make('Текст результата')->schema([
                Hidden::make('metric_result_content'),
                Select::make('result_metric_key')
                    ->label('Показатель для текста результата')
                    ->options(fn (Get $get): array => SurveyDefinitionFormOptions::metricOptions(
                        self::metrics($get),
                        $get('result_metric_key'),
                    ))
                    ->placeholder('Выберите показатель')
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state, mixed $old): void {
                        self::switchMetricResult($get, $set, $old, $state);
                    })
                    ->disabled(fn (Get $get): bool => self::hasLegacyScoring($get))
                    ->columnSpanFull(),
                Placeholder::make('metric_result_help')
                    ->label('Редактирование показателя')
                    ->content('Выберите показатель, чтобы изменить его пояснение, наблюдение и следующий шаг. Значения сохраняются отдельно для каждого показателя.'),
                self::metricResultText('attention_reason', 'Почему стоит обратить внимание'),
                self::metricResultText('attention_reason_en', 'Почему стоит обратить внимание на английском'),
                self::metricResultText('observation', 'Что наблюдать'),
                self::metricResultText('observation_en', 'Что наблюдать на английском'),
                self::metricResultText('road_map', 'Road Map / следующий шаг'),
                self::metricResultText('road_map_en', 'Road Map / следующий шаг на английском'),
                Textarea::make('summary')
                    ->label('Общий текст результата')
                    ->maxLength(10000)
                    ->disabled(fn (Get $get): bool => self::hasLegacyScoring($get))
                    ->columnSpanFull(),
                Textarea::make('summary_en')
                    ->label('Общий текст результата на английском')
                    ->maxLength(10000)
                    ->disabled(fn (Get $get): bool => self::hasLegacyScoring($get))
                    ->columnSpanFull(),
                Repeater::make('thresholds')
                    ->label('Диапазоны результата')
                    ->reorderable()
                    ->cloneable(false)
                    ->collapsed()
                    ->itemLabel(fn (array $state): string => self::thresholdSummary($state))
                    ->addActionLabel('Добавить диапазон')
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
            ])->columns(2)->columnSpanFull(),
            Section::make('Предупреждение и следующий шаг')->schema([
                Textarea::make('safe_steps_text')
                    ->label('Безопасные шаги')
                    ->helperText('Каждый пункт — с новой строки.')
                    ->maxLength(10000)
                    ->disabled(fn (Get $get): bool => self::hasLegacyScoring($get))
                    ->columnSpanFull(),
                Textarea::make('safe_steps_text_en')
                    ->label('Безопасные шаги на английском')
                    ->helperText('Каждый пункт — с новой строки.')
                    ->maxLength(10000)
                    ->disabled(fn (Get $get): bool => self::hasLegacyScoring($get))
                    ->columnSpanFull(),
                Textarea::make('specialist_questions_text')
                    ->label('Вопросы для обсуждения со специалистом')
                    ->helperText('Каждый вопрос — с новой строки.')
                    ->maxLength(10000)
                    ->disabled(fn (Get $get): bool => self::hasLegacyScoring($get))
                    ->columnSpanFull(),
                Textarea::make('specialist_questions_text_en')
                    ->label('Вопросы для специалиста на английском')
                    ->helperText('Каждый вопрос — с новой строки.')
                    ->maxLength(10000)
                    ->disabled(fn (Get $get): bool => self::hasLegacyScoring($get))
                    ->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make('Предпросмотр')->schema([
                Select::make('preview_metric_key')
                    ->label('Показатель для предпросмотра')
                    ->options(fn (Get $get): array => SurveyDefinitionFormOptions::metricOptions(
                        self::metrics($get),
                        $get('preview_metric_key'),
                    ))
                    ->dehydrated(false)
                    ->searchable()
                    ->live(),
                Placeholder::make('scoring_preview')
                    ->label('Как считается')
                    ->content(fn (Get $get): string => self::scoringPreview($get))
                    ->columnSpanFull(),
                Placeholder::make('result_preview')
                    ->label('Результаты')
                    ->content(fn (Get $get): string => self::resultPreview($get))
                    ->columnSpanFull(),
            ])->columns(2)->columnSpanFull(),
        ];
    }

    /** @return array<int, mixed> */
    private static function publicationSchema(): array
    {
        return [
            Section::make('Публикация и версия')->schema([
                Hidden::make('published_version_number')->dehydrated(false),
                Hidden::make('draft_version_number')->dehydrated(false),
                Placeholder::make('version_status')
                    ->label('Состояние версии')
                    ->content(fn (Get $get): string => self::versionStatus($get))
                    ->columnSpanFull(),
                Placeholder::make('new_scale_explanation')
                    ->label('Когда нужна новая шкала')
                    ->content('Используйте «Начать новую шкалу», если меняете сам принцип оценки так, что новые результаты нельзя корректно сравнивать со старыми.')
                    ->columnSpanFull(),
            ])->columns(2)->columnSpanFull(),
        ];
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

    private static function metricMaxIsDerived(Get $get): bool
    {
        $metricKey = $get('key');
        if (! is_string($metricKey) || $metricKey === '') {
            return false;
        }

        $rules = $get('/data.rules');

        return SurveyDefinitionScoringFormMapper::hasDerivedMaxValue(
            is_array($rules) ? $rules : [],
            $metricKey,
        );
    }

    /** @param array<string, mixed> $state */
    private static function sectionSummary(array $state): string
    {
        $count = is_array($state['questions'] ?? null) ? count($state['questions']) : 0;

        return self::text($state['title'] ?? null, 'Новый раздел').' · '.$count.' '.self::questionWord($count);
    }

    /** @param array<string, mixed> $state */
    private static function questionSummary(array $state): string
    {
        return self::text($state['label'] ?? null, 'Новый вопрос').' · '.SurveyDefinitionFormOptions::questionTypeLabel($state['type'] ?? null);
    }

    /** @param array<string, mixed> $state */
    private static function ruleSummary(array $state): string
    {
        return match ($state['operator'] ?? null) {
            'value_map' => 'Баллы по варианту ответа',
            'selected_sum' => 'Сумма выбранных вариантов',
            'numeric_value' => 'Числовой ответ',
            default => 'Новое правило подсчёта',
        };
    }

    /** @param array<string, mixed> $state */
    private static function thresholdSummary(array $state): string
    {
        $range = self::rangeLabel($state);

        return self::text($state['label'] ?? null, 'Новый результат').($range === '' ? '' : ' · '.$range);
    }

    private static function questionWord(int $count): string
    {
        return match (true) {
            $count % 10 === 1 && $count % 100 !== 11 => 'вопрос',
            in_array($count % 10, [2, 3, 4], true) && ! in_array($count % 100, [12, 13, 14], true) => 'вопроса',
            default => 'вопросов',
        };
    }

    private static function text(mixed $value, string $fallback = 'Без названия'): string
    {
        if (is_array($value)) {
            $value = $value['ru'] ?? $value['en'] ?? null;
        }

        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), 100) : $fallback;
    }

    private static function provenanceLabel(Get $get): string
    {
        return match ([$get('source'), $get('approval_status')]) {
            ['chuklov_approved', 'approved'] => 'Подтверждённый материал Чуклова',
            ['platform_default', 'draft'] => 'Платформенный демонстрационный черновик',
            default => 'Материал требует проверки происхождения',
        };
    }

    private static function methodologyLabel(Get $get): string
    {
        return match ($get('methodology')) {
            'platform_default_9_systems' => 'Демонстрационный опрос по девяти направлениям',
            'platform_extended_symptom_questionnaire' => 'Демонстрационный расширенный опрос симптомов',
            null, '' => 'Не указана',
            default => 'Указана в настройках материала',
        };
    }

    private static function metricResultText(string $key, string $label): Textarea
    {
        return Textarea::make('result_'.$key)
            ->label($label)
            ->maxLength(5000)
            ->live(onBlur: true)
            ->afterStateUpdated(function (Get $get, Set $set, mixed $state) use ($key): void {
                self::storeMetricResultField($get, $set, $key, $state);
            })
            ->disabled(fn (Get $get): bool => self::hasLegacyScoring($get))
            ->columnSpanFull();
    }

    private static function switchMetricResult(Get $get, Set $set, mixed $old, mixed $state): void
    {
        if (is_string($old) && $old !== '') {
            self::storeMetricResult($get, $set, $old);
        }
        if (! is_string($state) || $state === '') {
            return;
        }

        $content = $get('/data.metric_result_content');
        $content = is_array($content) && is_array($content[$state] ?? null) ? $content[$state] : [];
        foreach (['attention_reason', 'observation', 'road_map'] as $key) {
            $set('/data.result_'.$key, $content[$key] ?? null);
            $set('/data.result_'.$key.'_en', $content[$key.'_en'] ?? null);
        }
    }

    private static function storeMetricResultField(Get $get, Set $set, string $key, mixed $state): void
    {
        $metricKey = $get('/data.result_metric_key');
        if (! is_string($metricKey) || $metricKey === '') {
            return;
        }

        $content = $get('/data.metric_result_content');
        $content = is_array($content) ? $content : [];
        $metricContent = is_array($content[$metricKey] ?? null) ? $content[$metricKey] : [];
        $metricContent[$key] = is_string($state) && trim($state) !== '' ? $state : null;
        $content[$metricKey] = $metricContent;
        $set('/data.metric_result_content', $content);
    }

    private static function storeMetricResult(Get $get, Set $set, string $metricKey): void
    {
        $content = $get('/data.metric_result_content');
        $content = is_array($content) ? $content : [];
        $metricContent = is_array($content[$metricKey] ?? null) ? $content[$metricKey] : [];
        foreach (['attention_reason', 'observation', 'road_map'] as $key) {
            $value = $get('/data.result_'.$key);
            $englishValue = $get('/data.result_'.$key.'_en');
            $metricContent[$key] = is_string($value) && trim($value) !== '' ? $value : null;
            $metricContent[$key.'_en'] = is_string($englishValue) && trim($englishValue) !== '' ? $englishValue : null;
        }
        $content[$metricKey] = $metricContent;
        $set('/data.metric_result_content', $content);
    }

    private static function scoringPreview(Get $get): string
    {
        $sections = self::sections($get);
        $metrics = self::metrics($get);
        $metricKey = $get('/data.preview_metric_key');
        if (! is_string($metricKey) || $metricKey === '') {
            $metricKey = is_array($metrics[0] ?? null) ? ($metrics[0]['key'] ?? null) : null;
        }
        $rules = $get('/data.rules');
        $lines = [];
        foreach (is_array($rules) ? $rules : [] as $rule) {
            if (! is_array($rule) || ($rule['metric_key'] ?? null) !== $metricKey) {
                continue;
            }
            $questionKey = $rule['question_key'] ?? null;
            $questionLabel = SurveyDefinitionFormOptions::allQuestionOptions($sections, $questionKey)[(string) $questionKey] ?? 'Выбранный вопрос больше недоступен.';
            if (in_array($rule['operator'] ?? null, ['value_map', 'selected_sum'], true)) {
                $points = [];
                foreach (is_array($rule['points'] ?? null) ? $rule['points'] : [] as $point) {
                    if (! is_array($point)) {
                        continue;
                    }
                    $optionLabel = SurveyDefinitionFormOptions::optionOptions(
                        $sections,
                        $questionKey,
                        $point['value'] ?? null,
                    )[(string) ($point['value'] ?? '')] ?? 'Выбранный вариант больше недоступен.';
                    $points[] = $optionLabel.' = '.(is_numeric($point['points'] ?? null) ? (string) $point['points'] : 'баллы не заданы');
                }
                if ($points !== []) {
                    $lines[] = $questionLabel.': '.implode(' · ', $points);
                }

                continue;
            }
            if (($rule['operator'] ?? null) === 'numeric_value') {
                $multiplier = is_numeric($rule['multiplier'] ?? null) ? (string) $rule['multiplier'] : '1';
                $lines[] = $questionLabel.': числовой ответ × '.$multiplier;
            }
        }

        if ($lines !== []) {
            return implode("\n", $lines);
        }
        if (is_string($metricKey) && $metricKey !== '') {
            return 'Для выбранного показателя правила ещё не настроены.';
        }

        return 'Добавьте правила подсчёта, чтобы увидеть пример.';
    }

    private static function resultPreview(Get $get): string
    {
        $thresholds = $get('/data.thresholds');
        if (! is_array($thresholds) || $thresholds === []) {
            return 'Добавьте диапазоны результата, чтобы увидеть их здесь.';
        }

        $metricKey = $get('/data.preview_metric_key');
        if (! is_string($metricKey) || $metricKey === '') {
            $metrics = self::metrics($get);
            $metricKey = is_array($metrics[0] ?? null) ? ($metrics[0]['key'] ?? null) : null;
        }
        $lines = [];
        foreach ($thresholds as $threshold) {
            if (! is_array($threshold) || ($threshold['metric_key'] ?? null) !== $metricKey) {
                continue;
            }
            $lines[] = self::rangeLabel($threshold)."\n".self::text($threshold['label'] ?? null, 'Текст результата не задан');
        }

        return $lines === [] ? 'Для выбранного показателя диапазоны ещё не настроены.' : implode("\n\n", $lines);
    }

    /** @param array<string, mixed> $state */
    private static function rangeLabel(array $state): string
    {
        $hasMin = is_numeric($state['min'] ?? null);
        $hasMax = is_numeric($state['max'] ?? null);
        if ($hasMin && $hasMax) {
            return (string) $state['min'].'–'.(string) $state['max'];
        }
        if ($hasMin) {
            return (string) $state['min'].'+';
        }
        if ($hasMax) {
            return 'до '.(string) $state['max'];
        }

        return '';
    }

    private static function versionStatus(Get $get): string
    {
        $published = $get('published_version_number');
        $draft = $get('draft_version_number');
        if ($draft !== null && $draft !== '') {
            return $published === null || $published === ''
                ? 'Есть неопубликованные изменения.'
                : 'Опубликовано: версия '.$published.'. Черновик: версия '.$draft.'.';
        }
        if ($published !== null && $published !== '') {
            return 'Опубликовано: версия '.$published.'. Все изменения опубликованы.';
        }

        return 'Новая версия будет создана при сохранении.';
    }
}
