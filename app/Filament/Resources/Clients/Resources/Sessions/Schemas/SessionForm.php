<?php

namespace App\Filament\Resources\Clients\Resources\Sessions\Schemas;

use App\Filament\Pages\MedicalClinicalCatalogConfiguration;
use App\Filament\Resources\Clients\Resources\Sessions\Schemas\MedicalComplaintSelect;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\MedicalFrequencyPeriodCatalog;
use App\Modules\MedicalProfiles\Application\MedicalProblemTagCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Sessions\Application\MedicalClinicalCatalog;
use App\Modules\Sessions\Application\MedicalSessionFieldLimits;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Slider;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

final class SessionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Сеанс'))
                    ->schema([
                        DateTimePicker::make('occurred_at')
                            ->label(__('Дата и время сеанса'))
                            ->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())
                            ->required()
                            ->seconds(false)
                            ->placeholder(__('Выберите дату и время сеанса'))
                            ->live(),
                        Select::make('specialist_id')
                            ->label(__('Специалист'))
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->preload()
                            ->optionsLimit(50)
                            ->options(static fn (): array => self::specialistResults(''))
                            ->getSearchResultsUsing(static fn (string $search): array => self::specialistResults($search))
                            ->getOptionLabelUsing(static fn (mixed $value): ?string => self::specialistLabel($value))
                            ->live(),
                        Select::make('booking_id')
                            ->label(__('Запись на приём (необязательно)'))
                            ->helperText(__('Связан только с записями этого клиента и выбранного специалиста.'))
                            ->placeholder(__('Без записи на приём'))
                            ->searchable()
                            ->native(false)
                            ->getSearchResultsUsing(static fn (string $search, Select $component): array => self::bookingResults($search, $component))
                            ->getOptionLabelUsing(static fn (mixed $value, Select $component): ?string => self::bookingLabel($value, $component))
                            ->live(),
                        Placeholder::make('autosave_saving')
                            ->hiddenLabel()
                            ->content(__('Сохраняем изменения…'))
                            ->extraAttributes(['wire:loading' => ''])
                            ->columnSpanFull(),
                    ])->columns(2)->columnSpanFull(),
                Section::make(__('0. Показатели до сеанса'))
                    ->schema([
                        Placeholder::make('vitals_stage1c')->hiddenLabel()->content(__('Измерения до и после сеанса подключаются в этапе 1C. Пока заполните доступные данные в следующих блоках.')),
                    ])->collapsible()->columnSpanFull(),
                Section::make(__('1. Жалобы (боли)'))
                    ->schema([
                        Repeater::make('pains')
                            ->label(__('Боли'))
                            ->schema([
                                Hidden::make('lineage_key')->dehydrated()->nullable(),
                                MedicalComplaintSelect::make(),
                                TextInput::make('name')->label(__('Название или описание боли'))->maxLength(1000)->live(onBlur: true),
                                Select::make('frequency_mode')->label(__('Частота'))->options([
                                    'unspecified' => __('Не указана'),
                                    'permanent' => __('Постоянно'),
                                    'count' => __('Количество раз за период'),
                                ])->default('unspecified')->live(),
                                TextInput::make('frequency_count')->label(__('Количество'))->integer()->minValue(1)->live(onBlur: true)
                                    ->visible(fn (Get $get): bool => $get('frequency_mode') === 'count')
                                    ->required(fn (Get $get): bool => $get('frequency_mode') === 'count'),
                                Select::make('frequency_period_unit')->label(__('Период'))->options(fn (Get $get): array => app(MedicalFrequencyPeriodCatalog::class)->optionsForSelection(app(OrganizationContext::class)->id(), $get('frequency_period_unit')))
                                    ->visible(fn (Get $get): bool => $get('frequency_mode') === 'count')
                                    ->required(fn (Get $get): bool => $get('frequency_mode') === 'count')
                                    ->searchable(),
                                CheckboxList::make('time_of_day')->label(__('Время суток'))->options([
                                    'all' => __('Весь день'),
                                    'morning' => __('Утро'),
                                    'day' => __('День'),
                                    'evening' => __('Вечер'),
                                    'night' => __('Ночь'),
                                ])->columns(2)->default(['all'])->live(),
                                Hidden::make('before_score')->dehydrated()->nullable(),
                                Hidden::make('after_score')->dehydrated()->nullable(),
                                Hidden::make('state')->dehydrated()->default('active'),
                                Placeholder::make('complaint_state')
                                    ->hiddenLabel()
                                    ->content(fn (Get $get): string => match ((string) $get('state')) {
                                        'resolved' => __('Жалоба снята. При необходимости нажмите «Вернулась».', []),
                                        'returned' => __('Жалоба вернулась.', []),
                                        default => $get('needs_confirmation') ? __('Из анкеты, уточнить.', []) : '',
                                    })
                                    ->extraAttributes(fn (Get $get): array => [
                                        'class' => in_array($get('state'), ['resolved', 'returned'], true)
                                            ? 'text-gray-500 opacity-70'
                                            : 'text-amber-700',
                                    ])
                                    ->visible(fn (Get $get): bool => $get('state') === 'resolved' || $get('state') === 'returned' || (bool) $get('needs_confirmation')),
                                \Filament\Forms\Components\Toggle::make('needs_confirmation')
                                    ->label(__('Из анкеты, уточнить'))
                                    ->helperText(__('Снимите отметку после подтверждения или исправления жалобы.'))
                                    ->default(false)
                                    ->live(),
                                Slider::make('before_score_ui')
                                    ->label(__('До сеанса'))
                                    ->range(minValue: 0, maxValue: 10)
                                    ->step(1)
                                    ->default(0)
                                    ->nullable()
                                    ->dehydrated(false)
                                    ->live()
                                    ->afterStateUpdated(static function (Set $set, mixed $state): void {
                                        $set('before_score', is_numeric($state) ? (int) $state : null);
                                    })
                                    ->helperText(__('0 — нет боли · 10 — максимальная боль')),
                                Placeholder::make('before_score_display')
                                    ->label(__('Текущее значение до сеанса'))
                                    ->content(fn (Get $get): string => self::scoreDisplay($get('before_score'))),
                                Actions::make([
                                    Action::make('setBeforeZero')
                                        ->label(__('Указать 0'))
                                        ->icon('heroicon-o-check')
                                        ->color('gray')
                                        ->action(static function (Set $set): void {
                                            $set('before_score', 0);
                                            $set('before_score_ui', 0);
                                        })
                                        ->visible(fn (Get $get): bool => $get('before_score') === null),
                                    Action::make('clearBeforeScore')
                                        ->label(__('Без оценки'))
                                        ->icon('heroicon-o-x-mark')
                                        ->color('gray')
                                        ->action(static function (Set $set): void {
                                            $set('before_score_ui', 0);
                                            $set('before_score', null);
                                        })
                                        ->visible(fn (Get $get): bool => $get('before_score') !== null),
                                ])->columnSpanFull(),
                                Slider::make('after_score_ui')
                                    ->label(__('После сеанса'))
                                    ->range(minValue: 0, maxValue: 10)
                                    ->step(1)
                                    ->default(0)
                                    ->nullable()
                                    ->dehydrated(false)
                                    ->live()
                                    ->afterStateUpdated(static function (Set $set, mixed $state): void {
                                        $set('after_score', is_numeric($state) ? (int) $state : null);
                                    })
                                    ->helperText(__('0 — нет боли · 10 — максимальная боль')),
                                Placeholder::make('after_score_display')
                                    ->label(__('Текущее значение после сеанса'))
                                    ->content(fn (Get $get): string => self::scoreDisplay($get('after_score'))),
                                Actions::make([
                                    Action::make('setAfterZero')
                                        ->label(__('Указать 0'))
                                        ->icon('heroicon-o-check')
                                        ->color('gray')
                                        ->action(static function (Set $set): void {
                                            $set('after_score', 0);
                                            $set('after_score_ui', 0);
                                        })
                                        ->visible(fn (Get $get): bool => $get('after_score') === null),
                                    Action::make('clearAfterScore')
                                        ->label(__('Без оценки'))
                                        ->icon('heroicon-o-x-mark')
                                        ->color('gray')
                                        ->action(static function (Set $set): void {
                                            $set('after_score_ui', 0);
                                            $set('after_score', null);
                                        })
                                        ->visible(fn (Get $get): bool => $get('after_score') !== null),
                                ])->columnSpanFull(),
                                Placeholder::make('previous_after_score')
                                    ->label(__('После предыдущего сеанса'))
                                    ->content(fn (Get $get): string => self::scoreDisplay(self::previousAfterScore($get)))
                                    ->extraAttributes(['class' => 'text-gray-500']),
                                Actions::make([
                                    Action::make('returnComplaint')
                                        ->label(__('Вернулась'))
                                        ->icon('heroicon-o-arrow-path')
                                        ->color('warning')
                                        ->action(static function (Set $set): void {
                                            $set('state', 'returned');
                                        })
                                        ->visible(fn (Get $get): bool => $get('state') === 'resolved'),
                                ])->columnSpanFull(),
                                Select::make('tag_ids')
                                    ->label(__('Категория проблемы'))
                                    ->options(fn (Get $get): array => app(MedicalProblemTagCatalog::class)->optionsForSelection(app(OrganizationContext::class)->id(), (array) $get('tag_ids')))
                                    ->multiple()
                                    ->searchable()
                                    ->preload()
                                    ->helperText(fn (Get $get): string => self::problemTagHelperText($get('tag_ids')))
                                    ->hintAction(self::problemTagConfigurationAction()),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => (string) ($state['name'] ?? __('Новая боль')))
                            ->addActionLabel(__('+ Жалоба'))
                            ->extraItemActions([self::addTimeOfDayAction()])
                            ->columnSpanFull(),
                        Textarea::make('pain')->label(__('Особые жалобы'))->rows(3)->placeholder(__('Что ещё важно учесть специалисту'))->live(onBlur: true),
                    ])->columnSpanFull(),
                Section::make(__('2. Кинезиологические и мануальные тесты'))
                    ->schema([
                        Placeholder::make('test_catalog_empty')
                            ->content(__('Каталог клинических тестов пока пуст. Настройте его перед заполнением протокола.'))
                            ->visible(fn (): bool => app(MedicalClinicalCatalog::class)->testOptions(app(OrganizationContext::class)->id()) === [])
                            ->columnSpanFull(),
                        Actions::make([
                            Action::make('configureClinicalCatalog')
                                ->label(__('Настроить каталог тестов'))
                                ->url(MedicalClinicalCatalogConfiguration::getUrl())
                                ->visible(fn (): bool => app(MedicalClinicalCatalog::class)->testOptions(app(OrganizationContext::class)->id()) === [] && MedicalClinicalCatalogConfiguration::canAccess()),
                        ])->columnSpanFull(),
                        ClinicalTestProtocol::counters(),
                        ClinicalTestProtocol::field(),
                        Hidden::make('causes_manual_override')->default(false)->dehydrated(),
                        Placeholder::make('derived_causes')
                            ->label(__('Причины по результатам тестов'))
                            ->content(fn (Get $get): string => self::causeNames(self::derivedCauseIds($get)))
                            ->visible(fn (Get $get): bool => $get('causes_manual_override') !== true)
                            ->columnSpanFull(),
                        Actions::make([
                            Action::make('editCausesManually')
                                ->label(__('Изменить список вручную'))
                                ->icon('heroicon-o-pencil-square')
                                ->action(static function (Set $set): void {
                                    $set('causes_manual_override', true);
                                })
                                ->visible(fn (Get $get): bool => $get('causes_manual_override') !== true),
                            Action::make('useTestCauses')
                                ->label(__('Вернуться к причинам из тестов'))
                                ->icon('heroicon-o-arrow-uturn-left')
                                ->color('gray')
                                ->action(static function (Get $get, Set $set): void {
                                    $set('cause_ids', self::derivedCauseIds($get));
                                    $set('causes_manual_override', false);
                                })
                                ->visible(fn (Get $get): bool => $get('causes_manual_override') === true),
                        ])->columnSpanFull(),
                        Select::make('cause_ids')
                            ->label(__('Причины сеанса'))
                            ->options(fn (Get $get): array => app(MedicalClinicalCatalog::class)->causeOptionsForSelection(app(OrganizationContext::class)->id(), (array) $get('cause_ids')))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(static fn (Set $set): mixed => $set('causes_manual_override', true))
                            ->visible(fn (Get $get): bool => $get('causes_manual_override') === true)
                            ->columnSpanFull(),
                        Hidden::make('tests')->dehydrated()->nullable(),
                        Hidden::make('observations')->dehydrated()->nullable(),
                    ])->columnSpanFull(),
                Section::make(__('3. Первопричина нарушений и причины нарушений'))
                    ->schema([
                        Repeater::make('root_causes')
                            ->label(__('Первопричина нарушений'))
                            ->schema([
                                Select::make('cause_id')
                                    ->label(__('Причина из сеанса'))
                                    ->options(fn (Get $get): array => self::rootCauseOptions($get))
                                    ->searchable()
                                    ->nullable()
                                    ->live(),
                                Textarea::make('free_text')->label(__('Дополнительное описание'))->rows(2)->maxLength(MedicalSessionFieldLimits::MaxTextLength)->live(onBlur: true),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->maxItems(2)
                            ->reorderable()
                            ->addActionLabel(__('Добавить первопричину'))
                            ->columnSpanFull(),
                        Hidden::make('root_cause_hypothesis')->dehydrated()->nullable(),
                        Placeholder::make('legacy_root_cause_hypothesis')
                            ->label(__('Старая гипотеза первопричины (только для истории)'))
                            ->content(fn (Get $get): string => filled($get('root_cause_hypothesis'))
                                ? (string) $get('root_cause_hypothesis')
                                : __('Нет сохранённого значения'))
                            ->columnSpanFull(),
                        Placeholder::make('root_cause_ai_version')
                            ->label(__('Версия ИИ'))
                            ->content(__('Не подключена. ИИ будет доступен на этапе 2.'))
                            ->columnSpanFull(),
                        Actions::make([
                            Action::make('fillRootCauseStage2')
                                ->label(__('Заполнить с ИИ'))
                                ->icon('heroicon-o-sparkles')
                                ->disabled()
                                ->tooltip(__('ИИ подключается на этапе 2.')),
                        ])->columnSpanFull(),
                    ])->columnSpanFull(),
                Section::make(__('4. Пошаговый протокол сеанса'))
                    ->schema([
                        Repeater::make('protocol_steps')
                            ->label(__('Шаги протокола'))
                            ->schema([
                                Hidden::make('lineage_key')->dehydrated()->nullable(),
                                Textarea::make('text')->label(__('Текст шага'))->rows(2)->required()->maxLength(MedicalSessionFieldLimits::MaxTextLength)->live(onBlur: true),
                            ])
                            ->defaultItems(0)
                            ->reorderable()
                            ->collapsible()
                            ->addActionLabel(__('Добавить шаг'))
                            ->itemLabel(fn (array $state, ?int $index): string => 'Шаг '.((int) ($index ?? 0) + 1).(filled($state['text'] ?? null) ? ': '.(string) $state['text'] : ''))
                            ->columnSpanFull(),
                        Hidden::make('protocol')
                            ->dehydrated()
                            ->nullable()
                            ->hintAction(Action::make('fillProtocol')
                                ->label(__('Заполнить с ИИ'))
                                ->icon('heroicon-o-sparkles')
                                ->disabled()
                                ->tooltip(__('ИИ подключается на этапе 2.'))),
                        Placeholder::make('protocol_legacy_hint')->label(__('Историческое поле протокола'))->content(__('Старый монолитный текст сохранён для истории. Новые шаги сохраняются отдельно.'))->columnSpanFull(),
                        Placeholder::make('protocol_ai_hint')->label(__('Версия ИИ'))->content(__('Не подключена. ИИ будет доступен на этапе 2.'))->columnSpanFull(),
                        Actions::make([
                            Action::make('fillProtocolStage2')
                                ->label(__('Заполнить с ИИ'))
                                ->icon('heroicon-o-sparkles')
                                ->disabled()
                                ->tooltip(__('ИИ подключается на этапе 2.')),
                        ])->columnSpanFull(),
                    ])->collapsible()->columnSpanFull(),
                Section::make(__('5. Субъективные ощущения клиента после сеанса'))
                    ->schema([
                        Textarea::make('subjective_feelings')->hiddenLabel()->rows(4)->placeholder(__('Что клиент почувствовал после сеанса'))->live(onBlur: true),
                    ])->collapsible()->columnSpanFull(),
                Section::make(__('6. Визуальные изменения в осанке после сеанса'))
                    ->schema([
                        Textarea::make('visual_posture_diagnostics')->hiddenLabel()->rows(4)->placeholder(__('Изменения линии плеч, таза и позвоночника'))->live(onBlur: true),
                    ])->collapsible()->columnSpanFull(),
                Section::make(__('7. Результат после сеанса'))
                    ->schema([
                        Textarea::make('result')
                            ->hiddenLabel()
                            ->rows(4)
                            ->placeholder(__('Итог, который специалист вводит вручную'))
                            ->live(onBlur: true)
                            ->hintAction(Action::make('fillResult')
                                ->label(__('Заполнить с ИИ'))
                                ->icon('heroicon-o-sparkles')
                                ->disabled()
                                ->tooltip(__('ИИ подключается на этапе 2.'))),
                        Placeholder::make('result_ai_version')
                            ->label(__('Версия ИИ'))
                            ->content(__('Не подключена. ИИ будет доступен на этапе 2.'))
                            ->columnSpanFull(),
                    ])->collapsible()->columnSpanFull(),
                Section::make(__('8. Заметки терапевта'))
                    ->schema([
                        Textarea::make('therapist_notes')->hiddenLabel()->rows(4)->placeholder(__('Внутренние заметки специалиста. Клиент их не увидит.'))->live(onBlur: true),
                    ])->collapsible()->columnSpanFull(),
                Section::make(__('9. Эффективность проведённого сеанса'))
                    ->schema([
                        Placeholder::make('effectiveness_stage1b')->hiddenLabel()->content(__('Расчёты эффективности подключаются в этапе 1B. Данные для них сохраняются в жалобах и результатах выше.')),
                    ])->collapsible()->columnSpanFull(),
                Section::make(__('10. Рекомендации эксперта'))
                    ->schema([
                        Placeholder::make('recommendations_stage1b')->label(__('Версия ИИ'))->content(__('Рекомендации и отправка клиенту подключаются в этапе 1B; ИИ — на этапе 2.')),
                        Actions::make([
                            Action::make('fillRecommendations')
                                ->label(__('Заполнить с ИИ'))
                                ->icon('heroicon-o-sparkles')
                                ->disabled()
                                ->tooltip(__('ИИ подключается на этапе 2.')),
                        ]),
                    ])->collapsible()->columnSpanFull(),
                Section::make(__('Фото до / после'))
                    ->schema([
                        Placeholder::make('session_photos_after_save')
                            ->hiddenLabel()
                            ->content(__('Сначала сохраните сеанс. Затем добавьте несколько фотографий до и после сеанса на странице просмотра.'))
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make(__('Исторические значения'))
                    ->schema([
                        Placeholder::make('session_history_fields')
                            ->hiddenLabel()
                            ->content(__('Старые поля остаются доступными в истории и не показываются как активные поля новой формы.'))
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function scoreDisplay(mixed $value): string
    {
        return is_numeric($value) && (int) $value >= 0 && (int) $value <= 10
            ? __(':value / 10', ['value' => (int) $value])
            : __('Без оценки');
    }

    public static function addTimeOfDayAction(): Action
    {
        return Action::make('addTimeOfDay')
            ->label(__('+ время суток'))
            ->tooltip(__('+ время суток'))
            ->icon('heroicon-o-plus')
            ->color('gray')
            ->schema([
                Select::make('time_of_day')
                    ->label(__('Новое время суток'))
                    ->options([
                        'all' => __('Весь день'),
                        'morning' => __('Утро'),
                        'day' => __('День'),
                        'evening' => __('Вечер'),
                        'night' => __('Ночь'),
                    ])
                    ->required(),
            ])
            ->modalHeading(__('Добавить время суток'))
            ->modalSubmitActionLabel(__('Добавить'))
            ->action(static function (array $data, array $arguments, Repeater $component): void {
                $item = $component->getItemState($arguments['item'] ?? '');

                if (! is_array($item)) {
                    return;
                }

                if (! filled($item['name'] ?? null) && ! filled($item['complaint_definition_id'] ?? null)) {
                    Notification::make()
                        ->title(__('Сначала укажите жалобу'))
                        ->warning()
                        ->send();

                    return;
                }

                $timeOfDay = (string) ($data['time_of_day'] ?? '');
                $existingTimeOfDay = array_values(array_filter(
                    (array) ($item['time_of_day'] ?? []),
                    static fn (mixed $value): bool => is_string($value) && $value !== '',
                ));

                if (in_array($timeOfDay, $existingTimeOfDay, true)) {
                    Notification::make()
                        ->title(__('Это время суток уже добавлено'))
                        ->warning()
                        ->send();

                    return;
                }

                $copy = $item;
                $copy['time_of_day'] = [$timeOfDay];
                $copy['before_score'] = null;
                $copy['after_score'] = null;
                $copy['previous_after_score'] = null;
                $copy['before_score_ui'] = null;
                $copy['after_score_ui'] = null;
                $copy['needs_confirmation'] = false;
                $copy['state'] = 'active';
                $copy['lineage_key'] = (string) Str::uuid();

                $state = $component->getState();
                if (! is_array($state)) {
                    return;
                }

                $state[(string) Str::uuid()] = $copy;
                $component->state($state);
            });
    }

    private static function previousAfterScore(Get $get): mixed
    {
        $state = $get('');

        return is_array($state) ? ($state['previous_after_score'] ?? null) : null;
    }

    /** @return list<int> */
    private static function derivedCauseIds(Get $get): array
    {
        $testResults = $get('test_results');
        $ids = [];
        foreach (is_array($testResults) ? $testResults : [] as $test) {
            if (! is_array($test) || ! is_array($test['cause_ids'] ?? null)) {
                continue;
            }
            foreach ($test['cause_ids'] as $id) {
                $id = (int) $id;
                if ($id > 0 && ! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /** @param list<int> $causeIds */
    private static function causeNames(array $causeIds): string
    {
        if ($causeIds === []) {
            return __('Причины по результатам тестов пока не выбраны.');
        }

        $options = app(MedicalClinicalCatalog::class)->causeOptionsForSelection(
            app(OrganizationContext::class)->id(),
            $causeIds,
        );

        return implode(', ', array_values(array_intersect_key($options, array_flip($causeIds))));
    }

    /** @return array<int, string> */
    private static function problemTagOptions(mixed $selectedIds): array
    {
        return app(MedicalProblemTagCatalog::class)->optionsForSelection(
            app(OrganizationContext::class)->id(),
            is_array($selectedIds) ? $selectedIds : [],
        );
    }

    private static function problemTagHelperText(mixed $selectedIds): string
    {
        return self::problemTagOptions($selectedIds) === []
            ? __('Категории проблем ещё не настроены.')
            : __('Выберите одну или несколько категорий для статистики.');
    }

    private static function problemTagConfigurationAction(): Action
    {
        return Action::make('configureProblemCategories')
            ->label(__('Настроить категории'))
            ->url(MedicalClinicalCatalogConfiguration::getUrl())
            ->visible(fn (): bool => self::problemTagOptions([]) === [] && MedicalClinicalCatalogConfiguration::canAccess());
    }

    /** @return array<int, string> */
    private static function rootCauseOptions(Get $get): array
    {
        $causeOptions = app(MedicalClinicalCatalog::class)->causeOptions(app(OrganizationContext::class)->id());
        $testResults = $get('../../test_results');
        $causeIds = collect(is_array($testResults) ? $testResults : [])
            ->flatMap(static fn (mixed $test): array => is_array($test) && is_array($test['cause_ids'] ?? null)
                ? array_map(static fn (mixed $id): int => (int) $id, $test['cause_ids'])
                : [])
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->all();
        $selectedCauseIds = $get('../../cause_ids');
        if (is_array($selectedCauseIds)) {
            $causeIds = array_values(array_unique([...$causeIds, ...array_map(static fn (mixed $id): int => (int) $id, $selectedCauseIds)]));
        }
        $currentCauseId = (int) $get('cause_id');
        if ($currentCauseId > 0) {
            $causeIds[] = $currentCauseId;
        }

        return array_intersect_key($causeOptions, array_flip($causeIds));
    }

    private static function parentClient(Select $component): Client
    {
        $livewire = $component->getLivewire();
        abort_unless(method_exists($livewire, 'getParentRecord'), 404);
        $parent = $livewire->getParentRecord();

        abort_unless($parent instanceof Client, 404);

        return $parent;
    }

    /** @return array<int|string, string> */
    private static function specialistResults(string $search): array
    {
        $query = Specialist::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->when(trim($search) !== '', fn (Builder $query) => $query->where('display_name', 'like', '%'.trim($search).'%'))
            ->orderByDesc('is_active')
            ->orderBy('display_name')
            ->limit(50);

        return $query->get(['id', 'display_name', 'is_active'])
            ->mapWithKeys(static fn (Specialist $specialist): array => [
                $specialist->getKey() => self::specialistPresentation($specialist),
            ])
            ->all();
    }

    private static function specialistLabel(mixed $value): ?string
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $specialist = Specialist::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->whereKey((int) $value)
            ->first();

        return $specialist instanceof Specialist ? self::specialistPresentation($specialist) : null;
    }

    private static function specialistPresentation(Specialist $specialist): string
    {
        return __(':name (:status)', [
            'name' => $specialist->display_name,
            'status' => $specialist->is_active ? __('активен') : __('неактивен'),
        ]);
    }

    /** @return array<int|string, string> */
    private static function bookingResults(string $search, Select $component): array
    {
        $query = self::bookingQuery($component);
        $normalized = trim($search);

        if (ctype_digit($normalized)) {
            $query->whereKey((int) $normalized);
        } elseif ($normalized !== '') {
            try {
                $query->whereDate('starts_at', Carbon::parse($normalized));
            } catch (Throwable) {
                $query->whereKey(0);
            }
        }

        return $query
            ->orderByDesc('starts_at')
            ->limit(50)
            ->get(['id', 'starts_at'])
            ->mapWithKeys(static fn (Booking $booking): array => [
                $booking->getKey() => Carbon::parse((string) $booking->getAttribute('starts_at'), 'UTC')
                    ->setTimezone(app(OrganizationContext::class)->defaultTimezone())
                    ->format('d.m.Y H:i'),
            ])
            ->all();
    }

    private static function bookingLabel(mixed $value, Select $component): ?string
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $booking = self::bookingQuery($component)
            ->whereKey((int) $value)
            ->first(['id', 'starts_at']);

        return $booking instanceof Booking
            ? Carbon::parse((string) $booking->getAttribute('starts_at'), 'UTC')
                ->setTimezone(app(OrganizationContext::class)->defaultTimezone())
                ->format('d.m.Y H:i')
            : null;
    }

    /** @return Builder<Booking> */
    private static function bookingQuery(Select $component): Builder
    {
        $parent = self::parentClient($component);
        $specialistId = (int) ($component->getLivewire()->data['specialist_id'] ?? 0);

        return Booking::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where(fn (Builder $query) => $query
                ->whereHas('patientProfile', fn (Builder $patients) => $patients
                    ->where('organization_id', app(OrganizationContext::class)->id())->where('clinical_client_id', $parent->getKey()))
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('patient_profile_id')->where('client_id', $parent->getKey())))
            ->when($specialistId > 0, fn (Builder $query) => $query->where('specialist_id', $specialistId));
    }
}
