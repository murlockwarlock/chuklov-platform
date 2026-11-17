<?php

namespace App\Filament\Resources\Clients\Resources\Sessions\Schemas;

use App\Filament\Pages\MedicalClinicalCatalogConfiguration;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Sessions\Application\ApplyMedicalCauseToTests;
use App\Modules\Sessions\Application\MedicalCauseDirectory;
use App\Modules\Sessions\Application\MedicalClinicalCatalog;
use App\Modules\Sessions\Application\SaveMedicalCauseDefinition;
use App\Modules\Sessions\Domain\Enums\MedicalTestType;
use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class ClinicalTestProtocol
{
    /**
     * @param  array<int, mixed>  $previousResults
     * @return list<array<string, mixed>>
     */
    public static function defaultState(array $previousResults = []): array
    {
        $testIds = app(MedicalClinicalCatalog::class)->defaultSessionTestIds(app(OrganizationContext::class)->id());

        return self::stateFor(
            array_map(static fn (int $testId): array => ['test_id' => $testId], $testIds),
            $previousResults,
        );
    }

    /** @param array<int, mixed> $results
     * @param  array<int, mixed>  $previousResults
     * @return list<array<string, mixed>>
     */
    public static function stateFor(array $results, array $previousResults = []): array
    {
        $currentById = collect($results)
            ->filter(static fn (mixed $result): bool => is_array($result))
            ->keyBy(static fn (array $result): int => (int) ($result['test_id'] ?? 0));
        foreach ($previousResults as $previous) {
            if (! is_array($previous)) {
                continue;
            }
            $testId = (int) ($previous['test_id'] ?? 0);
            if ($testId < 1) {
                continue;
            }
            if ($currentById->has($testId)) {
                $current = $currentById->get($testId);
                if (is_array($current) && filled($previous['previous_after'] ?? null)) {
                    $current['previous_after'] = (string) $previous['previous_after'];
                    $current['review_again'] = true;
                    $current['priority'] = (bool) ($current['priority'] ?? false) || (bool) ($previous['priority'] ?? false);
                    if (($current['priority_regions'] ?? []) === [] && is_array($previous['priority_regions'] ?? null)) {
                        $current['priority_regions'] = array_values($previous['priority_regions']);
                    }
                    $currentById->put($testId, $current);
                }

                continue;
            }
            $currentById->put($testId, [
                'test_id' => $testId,
                'previous_after' => $previous['previous_after'] ?? null,
                'review_again' => true,
                'priority' => (bool) ($previous['priority'] ?? false),
                'priority_regions' => is_array($previous['priority_regions'] ?? null) ? array_values($previous['priority_regions']) : [],
            ]);
        }
        $results = $currentById->values()->all();
        $selected = collect($results)
            ->filter(static fn (mixed $result): bool => is_array($result))
            ->map(static fn (array $result): int => (int) ($result['test_id'] ?? 0))
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $existing = collect($results)
            ->filter(static fn (mixed $result): bool => is_array($result))
            ->keyBy(static fn (array $result): int => (int) ($result['test_id'] ?? 0));

        return array_values(app(MedicalClinicalCatalog::class)
            ->testProtocolRows(app(OrganizationContext::class)->id(), $selected)
            ->map(static function (MedicalTestDefinition $test) use ($existing): array {
                $testId = (int) $test->getKey();
                $result = $existing->get($testId, []);
                $type = $test->type();
                $label = filled($test->section) ? $test->section.' · '.$test->name : (string) $test->name;

                if (! $type instanceof MedicalTestType) {
                    return [
                        'test_id' => $testId,
                        'test_label' => $label,
                        'before_value_id' => $result['before_value_id'] ?? null,
                        'after_value_id' => $result['after_value_id'] ?? null,
                        'cause_ids' => is_array($result['cause_ids'] ?? null) ? array_values($result['cause_ids']) : [],
                    ];
                }

                $structured = is_array($result['structured'] ?? null)
                    ? $result['structured']
                    : self::blankStructured($test);
                $structured['review_again'] = (bool) ($result['review_again'] ?? $structured['review_again'] ?? false);

                return [
                    'test_id' => $testId,
                    'test_label' => $label,
                    'before_value_id' => $result['before_value_id'] ?? null,
                    'after_value_id' => $result['after_value_id'] ?? null,
                    'structured' => $structured,
                    'review_again' => (bool) ($structured['review_again'] ?? false),
                    'previous_after' => $result['previous_after'] ?? null,
                    'cause_ids' => is_array($result['cause_ids'] ?? null) ? array_values($result['cause_ids']) : [],
                'priority' => (bool) ($result['priority'] ?? false),
                'priority_regions' => is_array($result['priority_regions'] ?? null) ? array_values($result['priority_regions']) : [],
                ];
            })
            ->values()
            ->all());
    }

    public static function field(): Repeater
    {
        return Repeater::make('test_results')
            ->label(__('Клинические тесты'))
            ->schema(self::testSchema())
            ->default(self::defaultState())
            ->itemLabel(fn (array $state): string => self::itemLabel($state))
            ->addable(false)
            ->deletable(true)
            ->reorderable(true)
            ->reorderableWithButtons()
            ->collapsible()
            ->collapsed()
            ->afterStateUpdated(function (Component $livewire): void {
                if (method_exists($livewire, 'persistClinicalTestResults')) {
                    $livewire->persistClinicalTestResults();
                }
            })
            ->extraItemActions([self::editTestAction()])
            ->columnSpanFull();
    }

    public static function addTest(): Actions
    {
        return Actions::make([
            Action::make('addSpecializedTest')
                ->label(__('+ Тест'))
                ->icon('heroicon-o-plus')
                ->schema([
                    Select::make('test_id')
                        ->label(__('Специализированный тест'))
                        ->options(fn (): array => app(MedicalClinicalCatalog::class)->specializedTestOptions(app(OrganizationContext::class)->id()))
                        ->getSearchResultsUsing(fn (string $search): array => app(MedicalClinicalCatalog::class)->specializedTestOptions(app(OrganizationContext::class)->id(), $search))
                        ->searchable()
                        ->required(),
                ])
                ->modalSubmitActionLabel(__('Добавить тест'))
                ->action(function (array $data, Get $get, Set $set): void {
                    $testId = (int) ($data['test_id'] ?? 0);
                    if ($testId < 1) {
                        return;
                    }
                    $current = $get('test_results');
                    $current = is_array($current) ? array_values($current) : [];
                    if (collect($current)->contains(static fn (mixed $row): bool => is_array($row) && (int) ($row['test_id'] ?? 0) === $testId)) {
                        return;
                    }
                    $set('test_results', self::stateFor([...$current, ['test_id' => $testId]]), false, true);
                }),
        ])->columnSpanFull();
    }

    private static function editTestAction(): Action
    {
        return Action::make('editMedicalTest')
            ->label(__('Заполнить тест'))
            ->tooltip(__('Открыть структурированные поля теста'))
            ->icon('heroicon-o-pencil-square')
            ->schema(function (array $arguments, Repeater $component): array {
                $item = self::itemState($component, $arguments);
                $test = self::definition((int) ($item['test_id'] ?? 0));
                if (! $test instanceof MedicalTestDefinition || ! $test->type() instanceof MedicalTestType) {
                    return self::legacySchema();
                }

                return self::typedSchema($test);
            })
            ->fillForm(fn (array $arguments, Repeater $component): array => self::itemState($component, $arguments))
            ->modalHeading(__('Заполнить клинический тест'))
            ->modalSubmitActionLabel(__('Сохранить тест'))
            ->modalWidth('7xl')
            ->action(function (array $data, array $arguments, Repeater $component): void {
                $itemKey = (string) ($arguments['item'] ?? '');
                $state = $component->getRawState();
                if (! is_array($state) || ! is_array($state[$itemKey] ?? null)) {
                    return;
                }

                $existingItem = $state[$itemKey];
                $state[$itemKey] = array_replace($existingItem, $data);
                if (is_array($existingItem['structured'] ?? null) && is_array($data['structured'] ?? null)) {
                    $state[$itemKey]['structured'] = array_replace_recursive($existingItem['structured'], $data['structured']);
                }
                $component->rawState($state);
                $component->getChildSchema($itemKey)?->fill($state[$itemKey], shouldFillStateWithNull: false);
                $component->rawState($state);
                $component->callAfterStateUpdated();
                Notification::make()->success()->title(__('Тест сохранён в сеансе'))->send();
            });
    }

    /** @return array<string, mixed> */
    private static function itemState(Repeater $component, array $arguments): array
    {
        $itemKey = (string) ($arguments['item'] ?? '');
        if ($itemKey === '') {
            return [];
        }

        return $component->getRawItemState($itemKey);
    }

    public static function counters(): Placeholder
    {
        return Placeholder::make('test_counters')
            ->label(__('Нарушения'))
            ->content(function (Get $get): string {
                $results = $get('/data.test_results');
                $results = is_array($results) ? $results : [];
                $catalog = app(MedicalClinicalCatalog::class);
                $counts = method_exists($catalog, 'sessionCounters')
                    ? $catalog->sessionCounters(app(OrganizationContext::class)->id(), $results)
                    : [...$catalog->abnormalCounts(app(OrganizationContext::class)->id(), $results), 'resolved' => 0, 'new' => 0, 'unique_causes' => 0];

                return __('До: :before · После: :after · Устранено: :resolved · Новых: :new · Причин: :unique_causes · Стабилизировано: :stabilized · Сложных случаев: :complex', $counts);
            })
            ->live()
            ->columnSpanFull();
    }

    /** @return list<\Filament\Schemas\Components\Component> */
    private static function testSchema(): array
    {
        return [
            Hidden::make('test_id')->dehydrated()->live(),
            Hidden::make('test_label')->dehydrated(),
            Hidden::make('before_value_id')->dehydrated()->nullable(),
            Hidden::make('after_value_id')->dehydrated()->nullable(),
            Hidden::make('cause_ids')->dehydrated()->default([]),
            Hidden::make('structured')->dehydrated()->nullable(),
            Hidden::make('review_again')->dehydrated(),
            Hidden::make('previous_after')->dehydrated(),
            Placeholder::make('test_status')
                ->label(__('Состояние теста'))
                ->content(fn (Get $get): string => self::measurementSummary($get('structured'), (bool) $get('review_again')))
                ->columnSpanFull(),
            Placeholder::make('previous_after_hint')
                ->label(__('После предыдущего сеанса'))
                ->content(fn (Get $get): string => self::previousAfterLabel($get('previous_after')))
                ->extraAttributes(['class' => 'text-gray-500'])
                ->visible(fn (Get $get): bool => filled($get('previous_after')))
                ->columnSpanFull(),
        ];
    }

    /** @return list<\Filament\Schemas\Components\Component> */
    private static function typedSchema(MedicalTestDefinition $test): array
    {
        return [
            Hidden::make('test_id')->dehydrated(),
            Hidden::make('review_again')->dehydrated(),
            Hidden::make('previous_after')->dehydrated(),
            Toggle::make('priority')->label(__('Перепроверить в следующем сеансе'))->helperText(__('Тест поднимется выше в следующем сеансе.')),
            Select::make('priority_regions')
                ->label(__('Приоритетные регионы'))
                ->options(fn (Get $get): array => self::priorityRegionOptions((int) $get('test_id')))
                ->multiple()
                ->searchable()
                ->helperText(__('Можно отметить конкретные стороны или регионы для повторной проверки.')),
            Placeholder::make('test_type_hint')
                ->label(__('Тип ввода'))
                ->content(fn (): string => self::typeLabel($test->type()))
                ->columnSpanFull(),
            Placeholder::make('review_again_hint')
                ->hiddenLabel()
                ->content(__('Перепроверить'))
                ->extraAttributes(['class' => 'text-amber-700 font-semibold'])
                ->visible(fn (Get $get): bool => (bool) $get('review_again'))
                ->columnSpanFull(),
            Placeholder::make('previous_after_hint')
                ->label(__('После предыдущего сеанса'))
                ->content(fn (Get $get): string => self::previousAfterLabel($get('previous_after')))
                ->extraAttributes(['class' => 'text-gray-500'])
                ->visible(fn (Get $get): bool => filled($get('previous_after')))
                ->columnSpanFull(),
            Section::make(__('Общий индекс теста'))
                ->schema([
                    ToggleButtons::make('structured.general_index.before')->label(__('До сеанса'))->options([0 => '0', 1 => '1', 2 => '2', 3 => '3'])->inline(),
                    ToggleButtons::make('structured.general_index.after')->label(__('После сеанса'))->options([0 => '0', 1 => '1', 2 => '2', 3 => '3'])->inline(),
                ])
                ->columns(2)
                ->visible(fn (): bool => $test->type() !== MedicalTestType::T2)
                ->columnSpanFull(),
            self::phaseGroup('before', __('До сеанса'), $test),
            self::phaseGroup('after', __('После сеанса'), $test),
            Placeholder::make('typed_warnings')
                ->label(__('Предупреждения'))
                ->content(fn (Get $get): string => self::warnings($get))
                ->extraAttributes(['class' => 'text-amber-700'])
                ->visible(fn (Get $get): bool => self::warnings($get) !== '')
                ->columnSpanFull(),
            Textarea::make('correction_point')
                ->label(__('Точка коррекции'))
                ->rows(2)
                ->maxLength(1000)
                ->visible(fn (): bool => $test->type() === MedicalTestType::T4),
            ToggleButtons::make('violation_type')
                ->label(__('Тип нарушения'))
                ->options(fn (): array => self::simpleOptions(self::sessionConfiguration($test)['violation_types'] ?? []))
                ->inline()
                ->visible(fn (): bool => is_array(self::sessionConfiguration($test)['violation_types'] ?? null) && self::sessionConfiguration($test)['violation_types'] !== []),
        ];
    }

    /** @return list<\Filament\Schemas\Components\Component> */
    private static function legacySchema(): array
    {
        return [
            Hidden::make('test_id')->dehydrated(false),
            Toggle::make('priority')->label(__('Перепроверить в следующем сеансе'))->helperText(__('Тест поднимется выше в следующем сеансе.')),
            TextInput::make('priority_regions')->label(__('Приоритетные регионы'))->helperText(__('Укажите стороны или регионы через запятую.')),
            Select::make('before_value_id')
                ->label(__('До сеанса'))
                ->options(fn (Get $get): array => app(MedicalClinicalCatalog::class)->valueOptionsForSelection(app(OrganizationContext::class)->id(), (int) $get('test_id'), [$get('before_value_id')]))
                ->placeholder(__('Не заполнено'))
                ->nullable()
                ->searchable()
                ->live(),
            Select::make('after_value_id')
                ->label(__('После сеанса'))
                ->options(fn (Get $get): array => app(MedicalClinicalCatalog::class)->valueOptionsForSelection(app(OrganizationContext::class)->id(), (int) $get('test_id'), [$get('after_value_id')]))
                ->placeholder(__('Не заполнено'))
                ->nullable()
                ->searchable()
                ->live(),
            Select::make('cause_ids')
                ->label(__('Причины нарушений'))
                ->options(fn (Get $get): array => app(MedicalClinicalCatalog::class)->causeOptionsForSelection(app(OrganizationContext::class)->id(), (array) $get('cause_ids')))
                ->multiple()
                ->searchable()
                ->live(),
        ];
    }

    private static function phaseGroup(string $phase, string $label, MedicalTestDefinition $test): Section
    {
        $configuration = self::sessionConfiguration($test);
        $hasSubtests = is_array($configuration['subtests'] ?? null) && $configuration['subtests'] !== [];
        $hasRows = is_array($configuration['rows'] ?? null) && $configuration['rows'] !== [];
        $hasFeelings = is_array($configuration['feelings'] ?? null) && $configuration['feelings'] !== [];
        $hasSequence = collect(is_array($configuration['rows'] ?? null) ? $configuration['rows'] : [])
            ->contains(static fn (mixed $row): bool => is_array($row) && is_array($row['choices'] ?? null) && $row['choices'] !== []);

        $schema = [
            Hidden::make('allowed_cause_ids')->dehydrated(),
            Hidden::make('priority_options')->dehydrated(),
        ];

        if ($hasSequence) {
            $schema[] = self::sequenceField($configuration);
        }

        if ($test->type() === MedicalTestType::T4) {
            $schema[] = Placeholder::make('formula_preview')
                ->label(__('Формула и нарушенные переходы'))
                ->content(fn (Get $get): string => self::formulaDisplay($get('sequence')))
                ->extraAttributes(['class' => 'font-semibold'])
                ->columnSpanFull();
        }

        if ($test->type() === MedicalTestType::T2 && ($configuration['index_enabled'] ?? true)) {
            $schema[] = ToggleButtons::make('index')
                ->label(__('Общий индекс'))
                ->options([0 => '0', 1 => '1', 2 => '2', 3 => '3'])
                ->inline()
                ->live()
                ->afterStateUpdated(static function (Set $set, mixed $state): void {
                    if ((int) $state === 0) {
                        self::clearCauses($set);
                    }
                });
            $schema[] = Select::make('cause_ids')
                ->label(__('Причины метода'))
                ->options(fn (Get $get): array => self::allowedCauseOptions($get))
                ->multiple()
                ->searchable()
                ->live()
                ->visible(fn (Get $get): bool => (int) ($get('index') ?? 0) > 0);
            $schema[] = Select::make('primary_cause_ids')
                ->label(__('Главные причины'))
                ->options(fn (Get $get): array => self::allowedCauseOptions($get))
                ->multiple()
                ->searchable()
                ->helperText(__('До шести причин на регион.'))
                ->visible(fn (Get $get): bool => (int) ($get('index') ?? 0) > 0 && (array) $get('cause_ids') !== []);
        }

        if ($test->type() === MedicalTestType::T12 && ! $hasSubtests) {
            $schema[] = TextInput::make('energy')->label(__('Энергетический потенциал'))->numeric()->minValue(0)->maxValue(1000);
            $schema[] = ToggleButtons::make('tor_direction')->label(__('Тор-поле'))->options(['R' => __('Вправо'), 'L' => __('Влево')])->inline();
        }

        if ($hasSubtests) {
            $schema[] = self::subtestsField($test);
        }
        if ($hasRows) {
            $schema[] = self::rowsField($test);
        }
        if ($hasFeelings) {
            $schema[] = self::feelingsField($configuration, (int) $test->getKey());
        }

        if ($test->type() === MedicalTestType::T6) {
            $schema[] = Select::make('movement')->label(__('Движение провокации'))->options(self::movementOptions($configuration['movement_options'] ?? null))->nullable();
            $schema[] = TextInput::make('pain_intensity')->label(__('Интенсивность боли'))->numeric()->minValue(0)->maxValue(10);
            $schema[] = TextInput::make('tl_reduction_percent')->label(__('Снижение боли TL, %'))->numeric()->minValue(-100)->maxValue(100);
        }

        if (is_array($configuration['priority_options'] ?? null) && $configuration['priority_options'] !== []) {
            $schema[] = Select::make('priority_chain')
                ->label(__('Приоритетная цепь'))
                ->options(fn (Get $get): array => self::priorityOptions($get('priority_options')))
                ->nullable()
                ->searchable();
        }

        return Section::make($label)
            ->schema($schema)
            ->statePath('structured.'.$phase)
            ->collapsible()
            ->columnSpanFull();
    }

    private static function sequenceField(array $configuration): Repeater
    {
        $hasSequence = collect(is_array($configuration['rows'] ?? null) ? $configuration['rows'] : [])
            ->contains(static fn (mixed $row): bool => is_array($row) && is_array($row['choices'] ?? null) && $row['choices'] !== []);

        return Repeater::make('sequence')
            ->label(__('Формула по зонам'))
            ->schema([
                ToggleButtons::make('value')->label(__('Вариант'))->options(self::sequenceOptions($configuration))->inline()->required(),
            ])
            ->minItems(4)
            ->maxItems(4)
            ->defaultItems($hasSequence ? 4 : 0)
            ->addable($hasSequence)
            ->deletable($hasSequence)
            ->reorderable(false)
            ->visible($hasSequence)
            ->columnSpanFull();
    }

    private static function subtestsField(MedicalTestDefinition $test): Repeater
    {
        $subtests = self::sessionConfiguration($test)['subtests'] ?? [];

        return Repeater::make('subtests')
            ->label(__('Подтесты'))
            ->schema([
                Hidden::make('key')->dehydrated(),
                Hidden::make('type')->dehydrated(),
                Hidden::make('allowed_cause_ids')->dehydrated(),
                Hidden::make('method_options')->dehydrated(),
                Hidden::make('movement_options')->dehydrated(),
                Hidden::make('energy_enabled')->dehydrated(),
                Hidden::make('tor_enabled')->dehydrated(),
                Hidden::make('index_enabled')->dehydrated(),
                Hidden::make('choices')->dehydrated(),
                Toggle::make('enabled')->label(__('Включить подтест'))->live(),
                TextInput::make('label')->label(__('Подтест'))->readOnly()->dehydrated()->columnSpanFull(),
                ToggleButtons::make('index')
                    ->label(__('Общий индекс'))
                    ->options([0 => '0', 1 => '1', 2 => '2', 3 => '3'])
                    ->inline()
                    ->live()
                    ->afterStateUpdated(static function (Set $set, mixed $state): void {
                        if ((int) $state === 0) {
                            self::clearCauses($set);
                        }
                    })
                    ->visible(fn (Get $get): bool => $get('type') === MedicalTestType::T2->value && (bool) ($get('index_enabled') ?? true)),
                Select::make('cause_ids')
                    ->label(__('Причины метода'))
                    ->options(fn (Get $get): array => self::allowedCauseOptions($get))
                    ->multiple()
                    ->searchable()
                    ->live()
                    ->visible(fn (Get $get): bool => $get('type') === MedicalTestType::T2->value && (bool) ($get('index_enabled') ?? true) && (int) ($get('index') ?? 0) > 0),
                Select::make('primary_cause_ids')
                    ->label(__('Главные причины'))
                    ->options(fn (Get $get): array => self::allowedCauseOptions($get))
                    ->multiple()
                    ->searchable()
                    ->helperText(__('До шести причин на регион.'))
                    ->visible(fn (Get $get): bool => $get('type') === MedicalTestType::T2->value && (bool) ($get('index_enabled') ?? true) && (int) ($get('index') ?? 0) > 0 && (array) $get('cause_ids') !== []),
                TextInput::make('energy')->label(__('Энергетический потенциал'))->numeric()->minValue(0)->maxValue(1000)->visible(fn (Get $get): bool => (bool) $get('enabled') && (bool) $get('energy_enabled')),
                ToggleButtons::make('tor_direction')->label(__('Тор-поле'))->options(['R' => __('Вправо'), 'L' => __('Влево')])->inline()->visible(fn (Get $get): bool => (bool) $get('enabled') && (bool) $get('tor_enabled')),
                self::subtestRowsField('rows', 'Позиции', (int) $test->getKey())->visible(fn (Get $get): bool => (bool) $get('enabled')),
                self::subtestRowsField('provoking_points', __('Провоцирующие точки'), (int) $test->getKey())->visible(fn (Get $get): bool => (bool) $get('enabled') && $get('type') === MedicalTestType::T6->value),
                Repeater::make('feelings')->label(__('Чувства'))->schema(self::rowSchema())->addable(false)->deletable(false)->reorderable(false)->extraItemActions([self::addCauseAction((int) $test->getKey()), self::copyCausesAction()])->visible(fn (Get $get): bool => (bool) $get('enabled')),
                Select::make('method')
                    ->label(__('Метод'))
                    ->options(fn (Get $get): array => self::simpleOptions($get('method_options')))
                    ->nullable()
                    ->visible(fn (Get $get): bool => (bool) $get('enabled') && (array) $get('method_options') !== []),
                Select::make('movement')->label(__('Движение провокации'))->options(fn (Get $get): array => self::movementOptions($get('movement_options')))->nullable()->visible(fn (Get $get): bool => (bool) $get('enabled') && $get('type') === MedicalTestType::T6->value),
                TextInput::make('pain_intensity')->label(__('Интенсивность боли'))->numeric()->minValue(0)->maxValue(10)->visible(fn (Get $get): bool => (bool) $get('enabled') && $get('type') === MedicalTestType::T6->value),
                TextInput::make('tl_reduction_percent')->label(__('Снижение боли TL, %'))->numeric()->minValue(-100)->maxValue(100)->visible(fn (Get $get): bool => (bool) $get('enabled') && $get('type') === MedicalTestType::T6->value),
            ])
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->visible($subtests !== [])
            ->columnSpanFull();
    }

    private static function subtestRowsField(string $name = 'rows', string $label = 'Позиции', ?int $testId = null): Repeater
    {
        return Repeater::make($name)
            ->label(__($label))
            ->schema(self::rowSchema())
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->extraItemActions([self::addCauseAction($testId), self::copyCausesAction()])
            ->columnSpanFull();
    }

    private static function rowsField(MedicalTestDefinition $test): Repeater
    {
        $configuration = self::sessionConfiguration($test);

        return Repeater::make('rows')
            ->label(__('Позиции и стороны'))
            ->schema(self::rowSchema())
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->extraItemActions([self::addCauseAction((int) $test->getKey()), self::copyCausesAction()])
            ->visible(fn (): bool => is_array($configuration['rows'] ?? null) && $configuration['rows'] !== [])
            ->columnSpanFull();
    }

    /** @param array<string, mixed> $configuration */
    private static function feelingsField(array $configuration, ?int $testId = null): Repeater
    {
        return Repeater::make('feelings')
            ->label(__('Непроработанные чувства'))
            ->schema(self::rowSchema())
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->visible(is_array($configuration['feelings'] ?? null) && $configuration['feelings'] !== [])
            ->extraItemActions([self::addCauseAction($testId), self::copyCausesAction()])
            ->columnSpanFull();
    }

    private static function addCauseAction(?int $testId = null): Action
    {
        return Action::make('addCause')
            ->label(__('+ Причина'))
            ->icon('heroicon-o-plus')
            ->color('gray')
            ->schema([
                Select::make('cause_id')
                    ->label(__('Причина из общего справочника'))
                    ->options(fn (): array => self::globalCauseOptions())
                    ->getSearchResultsUsing(fn (string $search): array => self::globalCauseOptions($search))
                    ->searchable()
                    ->required()
                    ->helperText(__('Поиск проверяет название, группу и синонимы до создания новой причины.'))
                    ->createOptionForm([
                        TextInput::make('name')
                            ->label(__('Название причины'))
                            ->required()
                            ->maxLength(255),
                        Textarea::make('synonyms')
                            ->label(__('Синонимы'))
                            ->rows(3)
                            ->helperText(__('По одному синониму в строке')),
                        TextInput::make('group_name')
                            ->label(__('Группа'))
                            ->maxLength(255),
                        Textarea::make('tooltip')
                            ->label(__('Подсказка'))
                            ->rows(2)
                            ->maxLength(4000),
                    ])
                    ->createOptionUsing(static function (array $data): int {
                        $actor = Auth::user();
                        if (! $actor instanceof User) {
                            abort(403);
                        }

                        return (int) app(SaveMedicalCauseDefinition::class)->handle($actor, $data)->getKey();
                    }),
                Select::make('apply_test_ids')
                    ->label(__('Применить к…'))
                    ->options(fn (): array => app(MedicalClinicalCatalog::class)->testOptions(app(OrganizationContext::class)->id()))
                    ->multiple()
                    ->searchable()
                    ->helperText(__('Необязательно. Связь добавится к выбранным тестам в каталоге.')),
            ])
            ->modalSubmitActionLabel(__('Добавить причину'))
            ->action(function (array $arguments, array $data, Repeater $component) use ($testId): void {
                $causeId = (int) ($data['cause_id'] ?? 0);
                if ($causeId < 1) {
                    return;
                }

                $applyTestIds = is_array($data['apply_test_ids'] ?? null) ? $data['apply_test_ids'] : [];
                $actor = Auth::user();
                if ($applyTestIds !== []) {
                    if (! $actor instanceof User) {
                        abort(403);
                    }

                    try {
                        app(ApplyMedicalCauseToTests::class)->handle($actor, $causeId, $applyTestIds);
                    } catch (AuthorizationException) {
                        Notification::make()
                            ->danger()
                            ->title(__('Недостаточно прав для изменения каталога'))
                            ->body(__('Выберите причину из уже разрешённых для строки или попросите администратора применить её к тесту в CRM.'))
                            ->send();

                        return;
                    } catch (ValidationException $exception) {
                        $message = collect($exception->errors())->flatten()->first();
                        Notification::make()
                            ->danger()
                            ->title(__('Причину не удалось применить'))
                            ->body(is_string($message) ? $message : __('Проверьте выбранные тесты.'))
                            ->send();

                        return;
                    }
                }

                $state = $component->getState();
                if (! is_array($state)) {
                    return;
                }
                $itemKey = (string) ($arguments['item'] ?? '');
                $item = $state[$itemKey] ?? null;
                if (! is_array($item)) {
                    return;
                }

                $allowed = is_array($item['allowed_cause_ids'] ?? null)
                    ? array_values(array_unique(array_map('intval', $item['allowed_cause_ids'])))
                    : [];
                $currentTestWasSelected = $testId !== null && in_array($testId, array_map('intval', $applyTestIds), true);
                if (! in_array($causeId, $allowed, true) && $currentTestWasSelected) {
                    $allowed[] = $causeId;
                    $item['allowed_cause_ids'] = array_values(array_unique($allowed));
                }

                if (! in_array($causeId, $allowed, true)) {
                    Notification::make()
                        ->warning()
                        ->title(__('Причина найдена в общем справочнике'))
                        ->body(__('Чтобы выбрать её в этой строке, укажите текущий тест в поле «Применить к…» или настройте связь в CRM.'))
                        ->actions([
                            Action::make('configureCause')
                                ->label(__('Открыть CRM'))
                                ->url(MedicalClinicalCatalogConfiguration::getUrl())
                                ->button(),
                        ])
                        ->send();

                    return;
                }

                $selected = is_array($item['cause_ids'] ?? null) ? array_map('intval', $item['cause_ids']) : [];
                $item['cause_ids'] = array_values(array_unique([...$selected, $causeId]));
                $state[$itemKey] = $item;
                $component->state($state);
                Notification::make()->success()->title(__('Причина добавлена к строке'))->send();
            });
    }

    /** @return array<int, string> */
    private static function globalCauseOptions(string $term = ''): array
    {
        return app(MedicalCauseDirectory::class)
            ->search(app(OrganizationContext::class)->id(), $term)
            ->mapWithKeys(static function (MedicalCauseDefinition $cause): array {
                $label = filled($cause->group_name)
                    ? $cause->group_name.': '.MedicalClinicalCatalog::causeDisplayName((string) $cause->name)
                    : MedicalClinicalCatalog::causeDisplayName((string) $cause->name);

                return [(int) $cause->getKey() => $label];
            })
            ->all();
    }

    private static function copyCausesAction(): Action
    {
        return Action::make('copyCauses')
            ->label(__('Также'))
            ->icon('heroicon-o-clipboard-document')
            ->color('gray')
            ->action(static function (array $arguments, Repeater $component): void {
                $state = $component->getState();
                if (! is_array($state)) {
                    return;
                }
                $itemKey = (string) ($arguments['item'] ?? '');
                $keys = array_values(array_map('strval', array_keys($state)));
                $index = array_search($itemKey, $keys, true);
                if ($index === false || $index < 1 || ! is_array($state[$itemKey] ?? null)) {
                    Notification::make()->warning()->title(__('Для первой строки нет предыдущих причин'))->send();

                    return;
                }
                $previous = $state[$keys[$index - 1]] ?? null;
                if (! is_array($previous)) {
                    return;
                }
                $state[$itemKey]['cause_ids'] = is_array($previous['cause_ids'] ?? null) ? array_values($previous['cause_ids']) : [];
                $state[$itemKey]['primary_cause_ids'] = is_array($previous['primary_cause_ids'] ?? null)
                    ? array_values($previous['primary_cause_ids'])
                    : (($previous['primary_cause_id'] ?? null) === null ? [] : [(int) $previous['primary_cause_id']]);
                $component->state($state);
            });
    }

    /** @return list<\Filament\Schemas\Components\Component> */
    private static function rowSchema(): array
    {
        return [
            Hidden::make('key')->dehydrated(),
            Hidden::make('label')->dehydrated(),
            Hidden::make('dedupe_key')->dehydrated(),
            Hidden::make('side')->dehydrated(),
            Hidden::make('linked_segment')->dehydrated(),
            Hidden::make('method')->dehydrated(),
            Hidden::make('input_mode')->dehydrated(),
            Hidden::make('index_enabled')->dehydrated(),
            Hidden::make('allowed_cause_ids')->dehydrated(),
            Hidden::make('deviation_options')->dehydrated(),
            Hidden::make('tone_options')->dehydrated(),
            Hidden::make('finding_options')->dehydrated(),
            Hidden::make('answer_options')->dehydrated(),
            Hidden::make('angle_enabled')->dehydrated(),
            Hidden::make('unit_options')->dehydrated(),
            Hidden::make('phase_options')->dehydrated(),
            Hidden::make('fixed_movement_phase')->dehydrated(),
            Placeholder::make('label_display')->hiddenLabel()->content(fn (Get $get): string => (string) $get('label'))->columnSpanFull(),
            Placeholder::make('linked_segment_display')->hiddenLabel()->content(fn (Get $get): string => filled($get('linked_segment')) ? __('Связанный сегмент: :segment', ['segment' => $get('linked_segment')]) : '')->visible(fn (Get $get): bool => filled($get('linked_segment')))->columnSpanFull(),
            ToggleButtons::make('index')->label(__('Индекс'))->options([0 => '0', 1 => '1', 2 => '2', 3 => '3'])->inline()->live()->afterStateUpdated(static function (Set $set, mixed $state): void {
                if ((int) $state === 0) {
                    self::clearCauses($set);
                }
            })->visible(fn (Get $get): bool => (bool) $get('index_enabled')),
            Select::make('tone')->label(__('Тонус'))->options(fn (Get $get): array => self::toneOptions($get('tone_options')))->visible(fn (Get $get): bool => in_array($get('input_mode'), ['tone', 'traction'], true) && (int) ($get('index') ?? 0) > 0)->live(),
            Placeholder::make('traction_warning')->hiddenLabel()->content(__('вытяжение в этом отделе не делать'))->extraAttributes(['class' => 'text-amber-700 font-semibold'])->visible(fn (Get $get): bool => in_array($get('input_mode'), ['tone', 'traction'], true) && in_array(mb_strtolower(trim((string) $get('tone')), 'UTF-8'), ['hypotone', 'гипотонус'], true))->columnSpanFull(),
            Select::make('finding')->label(__('Находка'))->options(fn (Get $get): array => self::findingOptions($get('finding_options')))->visible(fn (Get $get): bool => in_array($get('input_mode'), ['finding', 'finding_list'], true))->live()->afterStateUpdated(static function (Set $set, mixed $state): void {
                if (! filled($state)) {
                    self::clearCauses($set);
                }
            }),
            ToggleButtons::make('answer')->label(__('Ответ'))->options(fn (Get $get): array => self::answerOptions($get('answer_options')))->inline()->visible(fn (Get $get): bool => in_array($get('input_mode'), ['yesno', 'finding_list'], true))->live()->afterStateUpdated(static function (Set $set, mixed $state): void {
                if ((string) $state !== 'yes') {
                    self::clearCauses($set);
                }
            }),
            Toggle::make('marked')->label(__('Отмечено'))->visible(fn (Get $get): bool => in_array($get('input_mode'), ['finding', 'finding_list', 'visual', 'energy_chakra'], true) && (! (bool) $get('index_enabled') || (int) ($get('index') ?? 0) !== 0))->live()->afterStateUpdated(static function (Set $set, mixed $state): void {
                if (! $state) {
                    self::clearCauses($set);
                }
            }),
            Select::make('deviation')
                ->label(__('Отклонение'))
                ->options(fn (Get $get): array => self::deviationOptions($get('deviation_options')))
                ->nullable()
                ->visible(fn (Get $get): bool => $get('input_mode') === 'visual')
                ->live(),
            TextInput::make('angle')->label(__('Угол, °'))->numeric()->minValue(0)->maxValue(180)->visible(fn (Get $get): bool => $get('input_mode') === 'mobility' && (bool) ($get('angle_enabled') ?? true)),
            TextInput::make('measurement')->label(__('Замер'))->numeric()->visible(fn (Get $get): bool => $get('input_mode') === 'measurement'),
            Select::make('unit')->label(__('Единица'))->options(fn (Get $get): array => self::simpleOptions($get('unit_options')))->nullable()->visible(fn (Get $get): bool => $get('input_mode') === 'measurement'),
            Select::make('movement_phase')
                ->label(__('Фаза движения'))
                ->options(fn (Get $get): array => self::simpleOptions($get('phase_options')))
                ->nullable()
                ->dehydrated()
                ->afterStateHydrated(function (Select $component, mixed $state, Get $get): void {
                    $fixedPhase = $get('fixed_movement_phase');
                    if (! filled($state) && filled($fixedPhase)) {
                        $component->state((string) $fixedPhase);
                    }
                })
                ->visible(fn (Get $get): bool => $get('input_mode') === 'measurement' && ! filled($get('fixed_movement_phase'))),
            TextInput::make('degrees')->label(__('Градусы'))->numeric()->visible(fn (Get $get): bool => $get('input_mode') === 'measurement'),
            Textarea::make('additional_answers')->label(__('Дополнительные ответы'))->rows(2)->maxLength(2000)->visible(fn (Get $get): bool => $get('input_mode') === 'measurement'),
            TextInput::make('pain_point')->label(__('Болевая точка'))->maxLength(255)->visible(fn (Get $get): bool => $get('input_mode') === 'pain_point'),
            Select::make('violation_type')->label(__('Тип нарушения'))->options(['механическое' => __('Механическое'), 'информационное' => __('Информационное')])->visible(fn (Get $get): bool => $get('input_mode') === 'violation_type'),
            Select::make('cause_ids')->label(__('Причины'))->options(fn (Get $get): array => self::allowedCauseOptions($get))->multiple()->searchable()->visible(fn (Get $get): bool => self::rowNeedsCauses($get))->live(),
            Select::make('primary_cause_ids')->label(__('Главные причины'))->options(fn (Get $get): array => self::allowedCauseOptions($get))->multiple()->searchable()->helperText(__('До шести причин на регион.'))->visible(fn (Get $get): bool => self::rowNeedsCauses($get) && is_array($get('cause_ids')) && $get('cause_ids') !== []),
        ];
    }

    private static function rowNeedsCauses(Get $get): bool
    {
        $finding = mb_strtolower(trim((string) $get('finding')), 'UTF-8');

        return (int) ($get('index') ?? 0) > 0
            || (bool) $get('marked')
            || in_array((string) $get('answer'), ['yes', 'да'], true)
            || ($finding !== '' && ! in_array($finding, ['normal', 'none', 'нет', 'норма'], true))
            || in_array((string) $get('deviation'), ['yes', 'да', 'true', '1', 'deviation', 'abnormal'], true);
    }

    private static function clearCauses(Set $set): void
    {
        $set('cause_ids', []);
        $set('primary_cause_id', null);
        $set('primary_cause_ids', []);
    }

    /** @return array<string, mixed> */
    private static function sessionConfiguration(MedicalTestDefinition $test): array
    {
        $configuration = $test->catalogConfiguration();
        $links = [];
        foreach ($test->causeLinks as $link) {
            if (! $link->is_active) {
                continue;
            }
            $scope = $link->scope_key === null ? '__root' : (string) $link->scope_key;
            $links[$scope][] = (int) $link->medical_cause_definition_id;
        }
        foreach ($links as $scope => $causeIds) {
            $links[$scope] = array_values(array_unique($causeIds));
        }

        $rootCauseIds = $links['__root'] ?? [];
        $configuration['cause_ids'] = $rootCauseIds;
        $attachRowCauses = static function (mixed $rows) use ($links, $rootCauseIds): array {
            if (! is_array($rows)) {
                return [];
            }
            foreach ($rows as &$row) {
                if (! is_array($row)) {
                    continue;
                }
                $key = trim((string) ($row['key'] ?? ''));
                $row['cause_ids'] = $key === ''
                    ? $rootCauseIds
                    : array_values(array_unique([...$rootCauseIds, ...($links[$key] ?? [])]));
            }
            unset($row);

            return array_values($rows);
        };
        $configuration['rows'] = $attachRowCauses($configuration['rows'] ?? []);
        $subtests = [];
        foreach (is_array($configuration['subtests'] ?? null) ? $configuration['subtests'] : [] as $subtest) {
            if (! is_array($subtest)) {
                continue;
            }
            $key = trim((string) ($subtest['key'] ?? ''));
            $subtest['cause_ids'] = $key === ''
                ? $rootCauseIds
                : array_values(array_unique([...$rootCauseIds, ...($links[$key] ?? [])]));
            $subtest['rows'] = $attachRowCauses($subtest['rows'] ?? []);
            $subtest['provoking_points'] = $attachRowCauses($subtest['provoking_points'] ?? []);
            $subtest['feelings'] = $attachRowCauses($subtest['feelings'] ?? []);
            $subtests[] = $subtest;
        }
        $configuration['subtests'] = $subtests;
        $configuration['feelings'] = $attachRowCauses($configuration['feelings'] ?? []);

        return $configuration;
    }

    /** @return array<string, mixed> */
    private static function blankStructured(MedicalTestDefinition $test): array
    {
        $configuration = self::sessionConfiguration($test);
        $type = $test->type();

        return [
            'before' => self::blankPhase($configuration, $type),
            'after' => self::blankPhase($configuration, $type),
            'general_index' => ['before' => null, 'after' => null],
            'cause_ids' => [],
            'primary_cause_id' => null,
            'primary_cause_ids' => [],
            'correction_point' => null,
            'violation_type' => null,
            'review_again' => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function blankPhase(array $configuration, ?MedicalTestType $type): array
    {
        $rows = [];
        foreach (is_array($configuration['rows'] ?? null) ? $configuration['rows'] : [] as $row) {
            if (is_array($row)) {
                $rows[] = self::blankRow($row, $type, $configuration);
            }
        }
        $subtests = [];
        foreach (is_array($configuration['subtests'] ?? null) ? $configuration['subtests'] : [] as $subtest) {
            if (! is_array($subtest)) {
                continue;
            }
            $subtestType = self::typeFromString($subtest['type'] ?? null) ?? $type;
            $subtestConfiguration = array_replace($configuration, $subtest);
            $subtestRows = [];
            foreach (is_array($subtest['rows'] ?? null) ? $subtest['rows'] : [] as $row) {
                if (is_array($row)) {
                    $subtestRows[] = self::blankRow($row, $subtestType, $subtestConfiguration);
                }
            }
            $provokingPoints = [];
            foreach (is_array($subtest['provoking_points'] ?? null) ? $subtest['provoking_points'] : [] as $row) {
                if (is_array($row)) {
                    $provokingPoints[] = self::blankRow($row, $subtestType, $subtestConfiguration);
                }
            }
            $defaultSubtests = is_array($configuration['default_subtests'] ?? null) ? $configuration['default_subtests'] : [];
            $subtests[] = [
                'key' => (string) ($subtest['key'] ?? ''),
                'label' => (string) ($subtest['label'] ?? ''),
                'type' => (string) ($subtest['type'] ?? $type?->value ?? ''),
                'enabled' => $defaultSubtests === [] || in_array((string) ($subtest['key'] ?? ''), $defaultSubtests, true),
                'index' => null,
                'allowed_cause_ids' => is_array($subtest['cause_ids'] ?? null) ? array_values($subtest['cause_ids']) : [],
                'cause_ids' => [],
                'primary_cause_id' => null,
                'primary_cause_ids' => [],
                'rows' => $subtestRows,
                'provoking_points' => $provokingPoints,
                'feelings' => array_map(
                    static fn (mixed $row): array => is_array($row) ? self::blankRow($row, $subtestType, $subtestConfiguration) : [],
                    is_array($subtest['feelings'] ?? null) ? $subtest['feelings'] : [],
                ),
                'energy' => null,
                'tor_direction' => null,
                'method_options' => is_array($subtest['method_options'] ?? null) ? array_values($subtest['method_options']) : [],
                'method' => null,
                'movement_options' => is_array($subtestConfiguration['movement_options'] ?? null) ? array_values($subtestConfiguration['movement_options']) : [],
                'energy_enabled' => array_key_exists('energy_enabled', $subtest)
                    ? (bool) $subtest['energy_enabled']
                    : array_key_exists('range', $subtest),
                'tor_enabled' => is_array($subtest['choices'] ?? null) && array_intersect(['R', 'L'], array_map('strval', $subtest['choices'])) !== [],
                'index_enabled' => (bool) ($subtest['index_enabled'] ?? true),
                'choices' => is_array($subtest['choices'] ?? null) ? array_values($subtest['choices']) : [],
            ];
        }

        $hasSequence = collect(is_array($configuration['rows'] ?? null) ? $configuration['rows'] : [])
            ->contains(static fn (mixed $row): bool => is_array($row) && is_array($row['choices'] ?? null) && $row['choices'] !== []);

        return [
            'rows' => $rows,
            'subtests' => $subtests,
            'sequence' => $hasSequence ? array_fill(0, 4, ['value' => null]) : [],
            'index' => null,
            'allowed_cause_ids' => is_array($configuration['cause_ids'] ?? null) ? array_values($configuration['cause_ids']) : [],
            'cause_ids' => [],
            'primary_cause_id' => null,
            'chakras' => [],
            'energy' => null,
            'tor_direction' => null,
            'movement' => null,
            'pain_intensity' => null,
            'tl_reduction_percent' => null,
            'priority_options' => is_array($configuration['priority_options'] ?? null) ? array_values($configuration['priority_options']) : [],
            'priority_chain' => null,
            'correction_point' => null,
            'violation_type' => null,
            'feelings' => array_map(
                static fn (mixed $row): array => is_array($row) ? self::blankRow($row, $type, $configuration) : [],
                is_array($configuration['feelings'] ?? null) ? $configuration['feelings'] : [],
            ),
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function blankRow(array $row, ?MedicalTestType $type, array $configuration = []): array
    {
        $inputMode = match ($type) {
            MedicalTestType::T1, MedicalTestType::T8 => 'tone',
            MedicalTestType::T3, MedicalTestType::T11 => 'finding_list',
            MedicalTestType::T5 => 'yesno',
            MedicalTestType::T6 => 'pain_point',
            MedicalTestType::T9 => 'mobility',
            MedicalTestType::T10 => 'visual',
            MedicalTestType::T12 => 'energy_chakra',
            MedicalTestType::T13 => 'measurement',
            default => 'index',
        };
        if (is_string($row['input_mode'] ?? null) && trim($row['input_mode']) !== '') {
            $inputMode = trim($row['input_mode']);
        }

        return [
            'key' => (string) ($row['key'] ?? ''),
            'label' => (string) ($row['label'] ?? ''),
            'side' => $row['side'] ?? null,
            'linked_segment' => $row['linked_segment'] ?? null,
            'method' => $row['method'] ?? null,
            'dedupe_key' => $row['dedupe_key'] ?? null,
            'index_enabled' => (bool) ($row['index_enabled'] ?? ! in_array($type, [MedicalTestType::T4, MedicalTestType::T5, MedicalTestType::T11, MedicalTestType::T12], true)),
            'input_mode' => $inputMode,
            'tone_options' => is_array($row['tone_options'] ?? ($configuration['tone_options'] ?? null))
                ? array_values($row['tone_options'] ?? $configuration['tone_options'])
                : [],
            'finding_options' => is_array($row['finding_options'] ?? ($configuration['finding_options'] ?? null))
                ? array_values($row['finding_options'] ?? $configuration['finding_options'])
                : [],
            'answer_options' => is_array($row['answer_options'] ?? ($configuration['answer_options'] ?? null))
                ? array_values($row['answer_options'] ?? $configuration['answer_options'])
                : [],
            'unit_options' => is_array($row['unit_options'] ?? ($configuration['measurement_units'] ?? null))
                ? array_values($row['unit_options'] ?? $configuration['measurement_units'])
                : [],
            'phase_options' => is_array($row['phase_options'] ?? ($configuration['movement_phases'] ?? null))
                ? array_values($row['phase_options'] ?? $configuration['movement_phases'])
                : [],
            'allowed_cause_ids' => is_array($row['cause_ids'] ?? null) ? array_values($row['cause_ids']) : [],
            'deviation_options' => is_array($row['deviation_options'] ?? null) ? array_values($row['deviation_options']) : [],
            'angle_enabled' => (bool) ($row['angle_enabled'] ?? true),
            'index' => null,
            'tone' => null,
            'finding' => null,
            'answer' => null,
            'marked' => false,
            'deviation' => null,
            'angle' => null,
            'pain_point' => null,
            'measurement' => null,
            'measurement_value' => null,
            'degrees' => null,
            'movement_phase' => $row['fixed_movement_phase'] ?? null,
            'fixed_movement_phase' => $row['fixed_movement_phase'] ?? null,
            'unit' => null,
            'additional_answers' => null,
            'violation_type' => null,
            'cause_ids' => [],
            'primary_cause_id' => null,
        ];
    }

    private static function definition(int $id): ?MedicalTestDefinition
    {
        if ($id < 1) {
            return null;
        }

        return MedicalTestDefinition::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->whereKey($id)
            ->first();
    }

    private static function itemLabel(array $state): string
    {
        $label = trim((string) ($state['test_label'] ?? __('Тест')));

        return (bool) ($state['review_again'] ?? false) ? $label.' · '.__('перепроверить') : $label;
    }

    private static function measurementSummary(mixed $structured, bool $reviewAgain): string
    {
        if ($reviewAgain) {
            return __('Перепроверить');
        }
        if (! is_array($structured)) {
            return __('Не заполнено');
        }

        $phases = [];
        foreach (['before' => 'До', 'after' => 'После'] as $phase => $label) {
            if (self::containsMeasurement($structured[$phase] ?? null)) {
                $phases[] = __($label.' заполнено');
            }
        }
        if (is_array($structured['general_index'] ?? null)
            && collect($structured['general_index'])->contains(static fn (mixed $value): bool => $value !== null && $value !== '')) {
            $phases[] = __('Общий индекс заполнен');
        }

        return $phases === [] ? __('Не заполнено') : implode(' · ', $phases);
    }

    private static function containsMeasurement(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach (['index', 'tone', 'finding', 'answer', 'deviation', 'angle', 'pain_point', 'measurement', 'measurement_value', 'degrees', 'unit', 'movement_phase', 'additional_answers', 'energy', 'tor_direction', 'method', 'movement', 'pain_intensity', 'tl_reduction_percent'] as $field) {
            if (array_key_exists($field, $value) && $value[$field] !== null && $value[$field] !== '') {
                return true;
            }
        }
        if (($value['marked'] ?? false) === true) {
            return true;
        }
        foreach (['sequence', 'rows', 'feelings', 'subtests', 'provoking_points'] as $field) {
            if (is_array($value[$field] ?? null) && collect($value[$field])->contains(static fn (mixed $child): bool => self::containsMeasurement($child))) {
                return true;
            }
        }

        return false;
    }

    private static function previousAfterLabel(mixed $value): string
    {
        return is_string($value) && trim($value) !== '' ? $value : __('Нет сохранённого значения');
    }

    private static function formulaDisplay(mixed $sequence): string
    {
        if (! is_array($sequence) || $sequence === []) {
            return __('Укажите четыре стороны формулы.');
        }

        $values = array_map(static fn (mixed $item): string => is_array($item)
            ? strtoupper(trim((string) ($item['value'] ?? '')))
            : strtoupper(trim((string) $item)), array_values($sequence));
        $parts = [];
        foreach ($values as $index => $value) {
            if ($index > 0) {
                $previous = $values[$index - 1] ?? '';
                $parts[] = $value !== '' && $value === $previous ? '⚠' : '→';
            }
            $parts[] = $value === '' ? '·' : $value;
        }

        return implode(' ', $parts);
    }

    /** @return array<string, string> */
    private static function sequenceOptions(array $configuration): array
    {
        $choices = [];
        foreach (is_array($configuration['rows'] ?? null) ? $configuration['rows'] : [] as $row) {
            if (is_array($row) && is_array($row['choices'] ?? null)) {
                $choices = $row['choices'];
                break;
            }
        }
        if ($choices === []) {
            $choices = ['L', 'R'];
        }

        return self::simpleOptions($choices);
    }

    /**
     * @return array<string, string>
     */
    private static function simpleOptions(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }
        $options = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $optionValue = trim((string) ($value['value'] ?? $value['key'] ?? ''));
                $label = trim((string) ($value['label'] ?? $optionValue));
            } elseif (is_string($key) && ! is_int($key)) {
                $optionValue = trim($key);
                $label = trim((string) $value);
            } else {
                $optionValue = trim((string) $value);
                $label = $optionValue;
            }
            if ($optionValue === '') {
                continue;
            }
            $options[$optionValue] = $label !== '' ? $label : $optionValue;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function toneOptions(mixed $values): array
    {
        $options = self::simpleOptions($values);

        return $options !== [] ? $options : [
            'normal' => __('Нормотонус'),
            'hypotone' => __('Гипотонус'),
            'hypertone' => __('Гипертонус'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function findingOptions(mixed $values): array
    {
        $options = self::simpleOptions($values);

        return $options !== [] ? $options : [
            'shift_right' => __('Смещение R'),
            'shift_left' => __('Смещение L'),
            'spasm' => __('Спазм'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function answerOptions(mixed $values): array
    {
        $options = self::simpleOptions($values);

        return $options !== [] ? $options : ['no' => __('Нет'), 'yes' => __('Да')];
    }

    /**
     * @return array<string, string>
     */
    private static function movementOptions(mixed $values): array
    {
        $options = self::simpleOptions($values);

        return $options !== [] ? $options : [
            'standing' => __('Стоя'),
            'bending' => __('Наклон'),
            'rising_from_chair' => __('Подъём со стула'),
            'walking' => __('Ходьба'),
            'rest' => __('Покой'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function deviationOptions(mixed $values): array
    {
        $options = self::simpleOptions($values);

        return $options !== [] ? $options : [
            'normal' => __('Норма'),
            'deviation' => __('Отклонение'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function priorityOptions(mixed $values): array
    {
        $options = self::simpleOptions($values);
        if ($options === []) {
            return [];
        }
        $labels = [
            'deep' => __('Глубокая'),
            'lateral' => __('Латеральная'),
            'front' => __('Передняя'),
            'back' => __('Задняя'),
            'spiral' => __('Спиральная'),
        ];

        $result = [];
        foreach ($options as $key => $label) {
            $result[$key] = $labels[$key] ?? $label;
        }

        return $result;
    }

    /** @return array<string, string> */
    private static function priorityRegionOptions(int $testId): array
    {
        $test = self::definition($testId);
        if (! $test instanceof MedicalTestDefinition) {
            return [];
        }
        $configuration = self::sessionConfiguration($test);
        $options = [];
        $addRows = static function (mixed $rows, string $prefix = '') use (&$options): void {
            if (! is_array($rows)) {
                return;
            }
            foreach ($rows as $row) {
                if (! is_array($row) || ! filled($row['key'] ?? null)) {
                    continue;
                }
                $key = trim((string) $row['key']);
                $label = trim((string) ($row['label'] ?? $key));
                $options[$prefix.$key] = $label;
            }
        };
        $addRows($configuration['rows'] ?? null);
        foreach (is_array($configuration['subtests'] ?? null) ? $configuration['subtests'] : [] as $subtest) {
            if (! is_array($subtest)) {
                continue;
            }
            $prefix = trim((string) ($subtest['key'] ?? '')).':';
            $addRows($subtest['rows'] ?? null, $prefix);
            $addRows($subtest['provoking_points'] ?? null, $prefix);
        }

        return $options;
    }

    /** @return array<int, string> */
    private static function allowedCauseOptions(Get $get): array
    {
        $allowed = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, is_array($get('allowed_cause_ids')) ? $get('allowed_cause_ids') : []),
            static fn (int $id): bool => $id > 0,
        )));
        $selected = is_array($get('cause_ids')) ? array_map(static fn (mixed $id): int => (int) $id, $get('cause_ids')) : [];
        $options = app(MedicalClinicalCatalog::class)->causeOptionsForSelection(
            app(OrganizationContext::class)->id(),
            [...$allowed, ...$selected],
        );

        return array_intersect_key($options, array_fill_keys([...$allowed, ...$selected], true));
    }

    private static function warnings(Get $get): string
    {
        $structured = $get('structured');
        if (! is_array($structured)) {
            return '';
        }
        $warnings = [];
        $storedWarnings = $structured['evaluation']['warnings'] ?? [];
        if (is_array($storedWarnings)) {
            $warnings = [...$warnings, ...array_filter($storedWarnings, 'is_string')];
        }
        foreach (['before', 'after'] as $phase) {
            $phaseWarnings = $structured[$phase]['warnings'] ?? [];
            if (is_array($phaseWarnings)) {
                $warnings = [...$warnings, ...array_filter($phaseWarnings, 'is_string')];
            }
        }

        return implode(' · ', array_values(array_unique($warnings)));
    }

    private static function typeLabel(?MedicalTestType $type): string
    {
        return match ($type) {
            MedicalTestType::T1 => __('Индекс и тонус по сторонам'),
            MedicalTestType::T2 => __('Метод-компьютер с общим индексом'),
            MedicalTestType::T3 => __('Пальпация по сегментам'),
            MedicalTestType::T4 => __('Четырёхзонная формула'),
            MedicalTestType::T5 => __('Ответы да/нет по позициям'),
            MedicalTestType::T6 => __('Локальные болевые точки'),
            MedicalTestType::T7 => __('Мышца или сегмент'),
            MedicalTestType::T8 => __('Вытяжение и тонус'),
            MedicalTestType::T9 => __('Угол и подвижность'),
            MedicalTestType::T10 => __('Визуальная карта'),
            MedicalTestType::T11 => __('Список находок'),
            MedicalTestType::T12 => __('Энергетические структуры'),
            MedicalTestType::T13 => __('Замеры и ортопедические пробы'),
            null => __('Исторический формат'),
        };
    }

    private static function typeFromString(mixed $value): ?MedicalTestType
    {
        return is_string($value) ? MedicalTestType::tryFrom($value) : null;
    }
}
