<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use App\Modules\MedicalProfiles\Domain\Models\MedicalComplaintDefinition;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Sessions\Application\ApplyMedicalCauseToTests;
use App\Modules\Sessions\Application\ImportMedicalClinicalCatalogCsv;
use App\Modules\Sessions\Application\MergeMedicalCauses;
use App\Modules\Sessions\Application\MedicalClinicalCatalog;
use App\Modules\Sessions\Application\MedicalStage1BCatalogPreset;
use App\Modules\Sessions\Application\SaveMedicalClinicalCatalog;
use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestValue;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;
use UnitEnum;

/** @property-read Schema $form */
final class MedicalClinicalCatalogConfiguration extends LocalizedPage
{
    protected static ?string $title = 'Клинические справочники';

    protected static ?string $navigationLabel = 'Клинические справочники';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 36;

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    /** @var array<int|string, string> */
    public array $causeOptions = [];

    public ?string $selectedTestKey = null;

    protected string $view = 'filament.pages.medical-clinical-catalog-configuration';

    public static function canAccess(): bool
    {
        $actor = Auth::user();
        if (! $actor instanceof User) {
            return false;
        }
        try {
            return app(OrganizationAuthorizer::class)->allows($actor, app(OrganizationContext::class)->organization(), OrganizationPermission::ManageSettings);
        } catch (LogicException) {
            return false;
        }
    }

    public function mount(): void
    {
        $organizationId = app(OrganizationContext::class)->id();
        $catalog = app(MedicalClinicalCatalog::class);
        $this->causeOptions = $catalog->allCauseOptions($organizationId);
        $tests = $catalog->catalogRows($organizationId)
            ->map(fn (MedicalTestDefinition $test): array => self::testFormState($test))
            ->all();
        $causes = MedicalCauseDefinition::query()
            ->where('organization_id', $organizationId)
            ->whereNull('merged_into_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (MedicalCauseDefinition $cause): array => [
                'id' => $cause->getKey(),
                'name' => $cause->name,
                'group_name' => $cause->group_name,
                'synonyms' => is_array($cause->synonyms) ? implode("\n", $cause->synonyms) : null,
                'tooltip' => $cause->tooltip,
                'moderation_status' => $cause->moderation_status,
                'is_active' => $cause->is_active,
                'sort_order' => $cause->sort_order,
            ])->all();
        $problemTags = MedicalProblemTag::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (MedicalProblemTag $tag): array => [
                'name' => $tag->name,
                'group_name' => $tag->group_name,
                'is_active' => $tag->is_active,
                'sort_order' => $tag->sort_order,
            ])->all();
        $complaints = MedicalComplaintDefinition::query()
            ->where('organization_id', $organizationId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (MedicalComplaintDefinition $complaint): array => [
                'id' => $complaint->getKey(),
                'name' => $complaint->name,
                'group_name' => $complaint->group_name,
                'synonyms' => is_array($complaint->synonyms) ? implode("\n", $complaint->synonyms) : null,
                'is_active' => $complaint->is_active,
                'sort_order' => $complaint->sort_order,
            ])->all();
        $selected = collect($tests)->first(fn (array $test): bool => ($test['key'] ?? null) === $this->selectedTestKey);
        $selected ??= $tests[0] ?? null;
        $this->selectedTestKey = is_array($selected) ? (string) ($selected['key'] ?? '') : null;
        $this->form->fill([
            'tests' => $tests,
            'selected_test_key' => $this->selectedTestKey,
            'selected_test' => $selected,
            'causes' => $causes,
            'problem_tags' => $problemTags,
            'complaints' => $complaints,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $this->compactForm($schema);
    }

    private function compactForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->testCatalogSection(),
                ...$this->directorySections(),
            ])
            ->statePath('data');
    }

    private function testCatalogSection(): Section
    {
        return Section::make(__('Тесты'))
            ->description(__('Настройте список тестов и откройте одну карточку для подробной настройки строк, позиций, причин и правил. Одновременное редактирование всех карточек не требуется.'))
            ->schema([
                Placeholder::make('tests_empty')
                    ->label(__('Каталог пока пуст'))
                    ->content(__('Добавьте первый тест кнопкой «Добавить тест». После сохранения он станет доступен в новом медицинском сеансе.'))
                    ->visible(fn (Get $get): bool => ! is_array($get('tests')) || $get('tests') === [])
                    ->columnSpanFull(),
                Repeater::make('tests')
                    ->label(__('Список тестов'))
                    ->helperText(__('Выберите тест ниже, чтобы открыть его подробную настройку. Перемещайте карточки кнопками или перетаскиванием; числовой порядок вводить не нужно.'))
                    ->schema([
                        Hidden::make('key')->default(fn (): string => 'test_'.Str::lower(Str::random(12))),
                        TextInput::make('name')->label(__('Название теста'))->live(onBlur: true)->required()->maxLength(255),
                        TextInput::make('section')->label(__('Раздел'))->maxLength(255),
                        Select::make('test_type')
                            ->label(__('Тип клинической формы'))
                            ->options(fn (): array => app(MedicalClinicalCatalog::class)->testTypeOptions())
                            ->searchable()
                            ->nullable(),
                        Select::make('catalog_set')
                            ->label(__('Набор'))
                            ->options(fn (): array => app(MedicalClinicalCatalog::class)->catalogSetOptions())
                            ->searchable()
                            ->nullable(),
                        Toggle::make('default_in_session')
                            ->label(__('В наборе по умолчанию'))
                            ->visible(fn (Get $get): bool => ($get('catalog_set') ?? null) === 'main')
                            ->default(false),
                        Toggle::make('is_active')->label(__('Активен'))->default(true),
                        Hidden::make('sort_order')->dehydrated(true),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->reorderable()
                    ->reorderableWithButtons()
                    ->collapsible()
                    ->collapsed()
                    ->itemLabel(fn (array $state): ?string => self::catalogItemLabel($state['name'] ?? null, $state['is_active'] ?? true))
                    ->addActionLabel(__('Добавить тест'))
                    ->columnSpanFull(),
                Select::make('selected_test_key')
                    ->label(__('Открыть тест для настройки'))
                    ->options(fn (Get $get): array => self::testEditorOptions($get('tests')))
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function (?string $state): void {
                        $this->selectTest($state);
                    })
                    ->helperText(__('В карточке теста доступны строки, подтесты, допустимые значения, причины и правила.'))
                    ->visible(fn (Get $get): bool => self::testEditorOptions($get('tests')) !== [])
                    ->columnSpanFull(),
                Section::make(__('Настройка выбранного теста'))
                    ->description(__('Сохраняйте только подтверждённые медицинские значения. Пустая норма оставляет автоматический расчёт выключенным.'))
                    ->schema($this->selectedTestSchema())
                    ->visible(fn (Get $get): bool => filled($get('selected_test_key')))
                    ->collapsible()
                    ->collapsed(false)
                    ->columnSpanFull(),
            ])
            ->columnSpanFull();
    }

    /** @return list<Section> */
    private function directorySections(): array
    {
        return [
            Section::make(__('Причины нарушений'))
                ->description(__('Причины из этого списка доступны специалисту для ручной корректировки в медицинском сеансе после выявленного нарушения. Отключённые и скрытые причины сохраняются в истории.'))
                ->schema([
                    Placeholder::make('causes_empty')
                        ->label(__('Справочник пока пуст'))
                        ->content(__('Добавьте первую причину кнопкой «Добавить причину».'))
                        ->visible(fn (Get $get): bool => ! is_array($get('causes')) || $get('causes') === [])
                        ->columnSpanFull(),
                    Repeater::make('causes')
                        ->label(__('Причины нарушений'))
                        ->helperText(__('Синонимы используются для поиска. Статус «На модерации» не разрешает выбор в сеансе; «Скрыта» сохраняет историю.'))
                        ->schema([
                            Hidden::make('id')->dehydrated()->nullable(),
                            TextInput::make('name')->label(__('Название причины'))->live(onBlur: true)->required()->maxLength(255),
                            TextInput::make('group_name')->label(__('Группа'))->maxLength(255),
                            Textarea::make('synonyms')->label(__('Синонимы'))->rows(2)->placeholder(__('По одному синониму в строке'))->maxLength(4000),
                            Textarea::make('tooltip')->label(__('Подсказка'))->rows(2)->placeholder(__('Короткая подсказка для поиска специалистом'))->maxLength(4000),
                            Select::make('moderation_status')
                                ->label(__('Статус модерации'))
                                ->options([
                                    MedicalCauseDefinition::MODERATION_ACTIVE => __('Активна'),
                                    MedicalCauseDefinition::MODERATION_PENDING => __('На модерации'),
                                    MedicalCauseDefinition::MODERATION_HIDDEN => __('Скрыта'),
                                ])
                                ->required()
                                ->default(MedicalCauseDefinition::MODERATION_ACTIVE),
                            Toggle::make('is_active')->label(__('Активна'))->default(true),
                            Hidden::make('sort_order')->dehydrated(true),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->reorderable()
                        ->reorderableWithButtons()
                        ->collapsible()
                        ->collapsed()
                        ->itemLabel(fn (array $state): string => self::catalogCauseItemLabel($state))
                        ->addActionLabel(__('Добавить причину'))
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            Section::make(__('Категории проблем'))
                ->description(__('Категории используются для классификации жалоб и статистики боли. Они не заменяют свободный текст жалобы.'))
                ->schema([
                    Placeholder::make('problem_tags_empty')
                        ->label(__('Категории пока не настроены'))
                        ->content(__('Добавьте первую категорию кнопкой «Добавить категорию».'))
                        ->visible(fn (Get $get): bool => ! is_array($get('problem_tags')) || $get('problem_tags') === [])
                        ->columnSpanFull(),
                    Repeater::make('problem_tags')
                        ->label(__('Категории проблем'))
                        ->schema([
                            TextInput::make('name')->label(__('Название категории'))->live(onBlur: true)->required()->maxLength(255),
                            TextInput::make('group_name')->label(__('Группа'))->maxLength(255),
                            Toggle::make('is_active')->label(__('Активна'))->default(true),
                            Hidden::make('sort_order')->dehydrated(true),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->reorderable()
                        ->reorderableWithButtons()
                        ->collapsible()
                        ->collapsed()
                        ->itemLabel(fn (array $state): string => self::catalogItemLabel($state['name'] ?? null, $state['is_active'] ?? true))
                        ->addActionLabel(__('Добавить категорию'))
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            Section::make(__('Жалобы'))
                ->description(__('Это единый справочник для клиентской анкеты и карточки сеанса. Отключённые жалобы остаются читаемыми в истории.'))
                ->schema([
                    Placeholder::make('complaints_empty')
                        ->label(__('Справочник пока пуст'))
                        ->content(__('Добавьте первую жалобу кнопкой «Добавить жалобу».'))
                        ->visible(fn (Get $get): bool => ! is_array($get('complaints')) || $get('complaints') === [])
                        ->columnSpanFull(),
                    Repeater::make('complaints')
                        ->label(__('Жалобы'))
                        ->schema([
                            Hidden::make('id')->dehydrated()->nullable(),
                            TextInput::make('name')->label(__('Название жалобы'))->live(onBlur: true)->required()->maxLength(255),
                            TextInput::make('group_name')->label(__('Группа'))->maxLength(255),
                            Textarea::make('synonyms')->label(__('Синонимы'))->rows(2)->placeholder(__('По одному синониму в строке'))->maxLength(2000),
                            Toggle::make('is_active')->label(__('Активна'))->default(true),
                            Hidden::make('sort_order')->dehydrated(true),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->reorderable()
                        ->reorderableWithButtons()
                        ->collapsible()
                        ->collapsed()
                        ->itemLabel(fn (array $state): string => self::catalogItemLabel($state['name'] ?? null, $state['is_active'] ?? true))
                        ->addActionLabel(__('Добавить жалобу'))
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
        ];
    }

    /** @return list<Component> */
    private function selectedTestSchema(): array
    {
        $path = static fn (string $field): string => 'selected_test.'.$field;

        return [
            Hidden::make($path('key'))->dehydrated(true),
            TextInput::make($path('name'))->label(__('Название теста'))->required()->maxLength(255),
            TextInput::make($path('section'))->label(__('Раздел'))->maxLength(255),
            Select::make($path('test_type'))
                ->label(__('Тип клинической формы'))
                ->options(fn (): array => app(MedicalClinicalCatalog::class)->testTypeOptions())
                ->searchable()
                ->nullable(),
            Select::make($path('catalog_set'))
                ->label(__('Набор'))
                ->options(fn (): array => app(MedicalClinicalCatalog::class)->catalogSetOptions())
                ->searchable()
                ->nullable(),
            Select::make($path('cause_ids'))
                ->label(__('Разрешённые причины'))
                ->options(fn (): array => $this->causeOptions)
                ->multiple()
                ->searchable()
                ->helperText(__('Пустой список допустим, если причины ещё не настроены.')),
            Toggle::make($path('default_in_session'))
                ->label(__('В наборе по умолчанию'))
                ->visible(fn (Get $get): bool => ($get($path('catalog_set')) ?? null) === 'main')
                ->default(false),
            Toggle::make($path('is_active'))->label(__('Активен'))->default(true),
            Hidden::make($path('sort_order'))->dehydrated(true),
            Section::make(__('Строки теста'))
                ->description(__('Строки описывают то, что специалист заполняет до и после сеанса.'))
                ->schema([
                    Repeater::make($path('rows'))
                        ->label(__('Строки'))
                        ->schema($this->catalogRowSchema('row'))
                        ->defaultItems(0)
                        ->reorderable()
                        ->reorderableWithButtons()
                        ->collapsible()
                        ->collapsed()
                        ->itemLabel(fn (array $state): ?string => self::catalogItemLabel($state['label'] ?? null, true))
                        ->addActionLabel(__('Добавить строку'))
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed()
                ->columnSpanFull(),
            Repeater::make($path('feelings'))
                ->label(__('Чувства и дополнительные находки'))
                ->schema($this->catalogRowSchema('feeling'))
                ->defaultItems(0)
                ->reorderable()
                ->reorderableWithButtons()
                ->collapsible()
                ->collapsed()
                ->addActionLabel(__('Добавить строку'))
                ->columnSpanFull(),
            Section::make(__('Подтесты'))
                ->description(__('Используйте подтесты, когда один номер содержит несколько самостоятельных частей, например 31.1, 31.2 и 31.3.'))
                ->schema([
                    Repeater::make($path('subtests'))
                        ->label(__('Подтесты'))
                        ->schema([
                            TextInput::make('key')->label(__('Обозначение подтеста'))->required()->maxLength(160),
                            TextInput::make('label')->label(__('Название подтеста'))->required()->maxLength(255),
                            Select::make('type')->label(__('Тип формы подтеста'))->options(fn (): array => app(MedicalClinicalCatalog::class)->testTypeOptions())->nullable()->searchable(),
                            Select::make('cause_ids')->label(__('Разрешённые причины'))->options(fn (): array => $this->causeOptions)->multiple()->searchable(),
                            Toggle::make('index_enabled')->label(__('Показывать индекс 0–3'))->default(true),
                            TagsInput::make('choices')->label(__('Допустимые варианты'))->placeholder(__('Добавьте вариант')),
                            TextInput::make('range.min')->label(__('Минимальное значение'))->numeric()->nullable(),
                            TextInput::make('range.max')->label(__('Максимальное значение'))->numeric()->nullable(),
                            Toggle::make('energy_enabled')->label(__('Показывать энергетическое значение'))->default(false),
                            TagsInput::make('method_options')->label(__('Допустимые методы'))->placeholder(__('Добавьте вариант метода')),
                            Repeater::make('rows')->label(__('Строки подтеста'))->schema($this->catalogRowSchema('subtest_row'))->defaultItems(0)->reorderable()->reorderableWithButtons()->collapsible()->collapsed()->addActionLabel(__('Добавить строку подтеста'))->columnSpanFull(),
                            Repeater::make('provoking_points')->label(__('Провоцирующие точки'))->schema($this->catalogRowSchema('provoking_point'))->defaultItems(0)->reorderable()->reorderableWithButtons()->collapsible()->collapsed()->addActionLabel(__('Добавить провоцирующую точку'))->columnSpanFull(),
                            Repeater::make('feelings')->label(__('Чувства и находки'))->schema($this->catalogRowSchema('feeling'))->defaultItems(0)->reorderable()->reorderableWithButtons()->collapsible()->collapsed()->addActionLabel(__('Добавить строку'))->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->reorderable()
                        ->reorderableWithButtons()
                        ->collapsible()
                        ->collapsed()
                        ->itemLabel(fn (array $state): ?string => self::catalogItemLabel($state['label'] ?? $state['key'] ?? null, true))
                        ->addActionLabel(__('Добавить подтест'))
                        ->columnSpanFull(),
                    Select::make($path('default_subtests'))
                        ->label(__('Подтесты по умолчанию'))
                        ->options(fn (Get $get): array => self::subtestOptions($get($path('subtests'))))
                        ->multiple()
                        ->searchable()
                        ->helperText(__('Оставьте пустым, если по умолчанию добавляется весь тест.'))
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed()
                ->columnSpanFull(),
            Section::make(__('Правила и подсказки'))
                ->schema([
                    CheckboxList::make($path('rules.index_violation'))->label(__('Индексы, считающиеся нарушением'))->options([0 => '0 · норма', 1 => '1', 2 => '2', 3 => '3'])->columns(4),
                    Toggle::make($path('index_enabled'))->label(__('Показывать общий индекс 0–3'))->default(true),
                    TextInput::make($path('rules.energy_threshold'))->label(__('Порог энергетического показателя'))->numeric()->nullable(),
                    Select::make($path('rules.normal_tor_direction'))->label(__('Нормальное направление тор-поля'))->options(['R' => 'Правая сторона', 'L' => 'Левая сторона'])->nullable(),
                    Toggle::make($path('rules.chakra_violation'))->label(__('Отмеченная чакра считается нарушением')),
                    Toggle::make($path('rules.broken_transition_count'))->label(__('Считать повторяющиеся переходы')),
                    Toggle::make($path('rules.ankle_sides'))->label(__('Для голеностопа указывать стороны')),
                    Toggle::make($path('rules.forward_bend_without_side'))->label(__('Для наклона поясницы не указывать сторону')),
                    TagsInput::make($path('priority_options'))->label(__('Варианты приоритета'))->placeholder(__('Добавьте вариант')),
                    TagsInput::make($path('movement_options'))->label(__('Движения провокации'))->placeholder(__('Добавьте вариант движения')),
                    TagsInput::make($path('tone_options'))->label(__('Варианты тонуса'))->placeholder(__('Добавьте вариант тонуса')),
                    TagsInput::make($path('finding_options'))->label(__('Варианты находки'))->placeholder(__('Добавьте вариант находки')),
                    TagsInput::make($path('answer_options'))->label(__('Варианты ответа'))->placeholder(__('Добавьте вариант ответа')),
                    TagsInput::make($path('violation_types'))->label(__('Типы нарушения'))->placeholder(__('Добавьте тип нарушения')),
                    Repeater::make($path('rules.allowed_sequences'))
                        ->label(__('Допустимые последовательности'))
                        ->schema([Select::make('values')->label(__('Варианты последовательности'))->options(['L' => 'Левая сторона', 'R' => 'Правая сторона'])->multiple()->required()])
                        ->defaultItems(0)
                        ->reorderable()
                        ->reorderableWithButtons()
                        ->addActionLabel(__('Добавить последовательность'))
                        ->columnSpanFull(),
                    Textarea::make($path('help_text'))->label(__('Подсказка для специалиста'))->rows(2)->maxLength(2000)->columnSpanFull(),
                    Textarea::make($path('safety_marker_text'))->label(__('Предупреждение по безопасности'))->rows(2)->maxLength(2000)->columnSpanFull(),
                ])
                ->columns(2)
                ->collapsible()
                ->collapsed()
                ->columnSpanFull(),
            Repeater::make($path('values'))
                ->label(__('Допустимые значения'))
                ->helperText(__('Добавьте варианты, которые специалист сможет выбрать для этого теста.'))
                ->schema([
                    Hidden::make('key')->default(fn (): string => 'value_'.Str::lower(Str::random(12))),
                    TextInput::make('label')->label(__('Название значения'))->required()->maxLength(255),
                    Select::make('value_type')->label(__('Тип значения'))->options(fn (): array => app(MedicalClinicalCatalog::class)->valueTypeOptions())->nullable(),
                    Toggle::make('is_abnormal')->label(__('Считать нарушением')),
                    Toggle::make('is_active')->label(__('Активно'))->default(true),
                    Hidden::make('sort_order')->dehydrated(true),
                ])
                ->columns(2)
                ->defaultItems(0)
                ->reorderable()
                ->reorderableWithButtons()
                ->collapsible()
                ->collapsed()
                ->itemLabel(fn (array $state): ?string => self::catalogItemLabel($state['label'] ?? null, $state['is_active'] ?? true))
                ->addActionLabel(__('Добавить значение'))
                ->columnSpanFull(),
        ];
    }

    /** @param mixed $tests
     * @return array<string, string>
     */
    private static function testEditorOptions(mixed $tests): array
    {
        if (! is_array($tests)) {
            return [];
        }

        $typeLabels = app(MedicalClinicalCatalog::class)->testTypeOptions();
        $options = [];
        foreach ($tests as $test) {
            if (! is_array($test) || ! filled($test['key'] ?? null)) {
                continue;
            }
            $label = trim((string) ($test['name'] ?? ''));
            if ($label === '') {
                $label = __('Новый тест');
            }
            $type = trim((string) ($test['test_type'] ?? $test['type'] ?? ''));
            if ($type !== '' && isset($typeLabels[$type])) {
                $label .= ' · '.$typeLabels[$type];
            }
            $options[(string) $test['key']] = $label;
        }

        return $options;
    }

    public function selectTest(?string $key): void
    {
        $state = $this->form->getState();
        $tests = $this->testsForSave($state, $this->selectedTestKey);
        $selected = collect($tests)->first(fn (array $test): bool => ($test['key'] ?? null) === $key);
        $this->selectedTestKey = $key;
        $this->form->fill([
            ...$state,
            'tests' => $tests,
            'selected_test_key' => $key,
            'selected_test' => $selected,
        ]);
    }

    /** @param array<string, mixed> $state
     * @return list<array<string, mixed>>
     */
    private function testsForSave(array $state, ?string $editorKey = null): array
    {
        $stored = is_array($this->data['tests'] ?? null) ? $this->data['tests'] : [];
        $submitted = is_array($state['tests'] ?? null) ? $state['tests'] : $stored;
        $storedByKey = [];
        foreach ($stored as $storedTest) {
            if (is_array($storedTest) && filled($storedTest['key'] ?? null)) {
                $storedByKey[(string) $storedTest['key']] = $storedTest;
            }
        }
        $byKey = [];
        foreach ($submitted as $test) {
            if (! is_array($test) || ! filled($test['key'] ?? null)) {
                continue;
            }
            $key = (string) $test['key'];
            $byKey[$key] = array_replace_recursive(
                $storedByKey[$key] ?? [],
                $test,
            );
        }
        if ($editorKey !== null && $editorKey !== '' && is_array($state['selected_test'] ?? null)) {
            $byKey[$editorKey] = array_replace_recursive($byKey[$editorKey] ?? [], $state['selected_test']);
        }

        return array_values($byKey);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('medical-clinical-catalog-form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')->label(__('Сохранить каталог'))->submit('save'),
                ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prepareStage1BCatalog')
                ->label(__('Подготовить каталог Stage 1B'))
                ->icon('heroicon-o-beaker')
                ->color('gray')
                ->authorize(fn (): bool => self::canAccess())
                ->requiresConfirmation()
                ->modalHeading(__('Подготовить 31 тест Stage 1B'))
                ->modalDescription(__('Будет добавлен исходный безопасный набор из 31 настраиваемого теста. Существующие тесты, причины и история сохранятся. Списки причин останутся пустыми, пока их не настроят через CRM.'))
                ->visible(fn (): bool => self::canAccess() && ! $this->hasStage1BTests())
                ->action(function (): void {
                    $actor = Auth::user();
                    if (! $actor instanceof User) {
                        abort(403);
                    }

                    $state = $this->form->getState();
                    $currentTests = $this->testsForSave($state, $this->selectedTestKey);
                    $currentByKey = [];
                    foreach ($currentTests as $test) {
                        if (is_array($test) && filled($test['key'] ?? null)) {
                            $currentByKey[(string) $test['key']] = $test;
                        }
                    }
                    foreach (MedicalStage1BCatalogPreset::definitions() as $definition) {
                        $currentByKey[(string) $definition['key']] = $definition;
                    }

                    app(SaveMedicalClinicalCatalog::class)->handle(
                        $actor,
                        array_values($currentByKey),
                        is_array($state['causes'] ?? null) ? $state['causes'] : [],
                        is_array($state['problem_tags'] ?? null) ? $state['problem_tags'] : [],
                        is_array($state['complaints'] ?? null) ? $state['complaints'] : [],
                    );
                    $this->mount();
                    Notification::make()->success()->title(__('Каталог Stage 1B подготовлен'))->send();
                }),
            Action::make('applyCauseToTests')
                ->label(__('Применить причину к…'))
                ->icon('heroicon-o-link')
                ->color('gray')
                ->authorize(fn (): bool => self::canAccess())
                ->form([
                    Select::make('cause_id')
                        ->label(__('Причина'))
                        ->options(fn (): array => $this->causeDirectoryOptions(onlySelectable: true))
                        ->searchable()
                        ->required(),
                    Select::make('test_ids')
                        ->label(__('Тесты'))
                        ->options(fn (): array => app(MedicalClinicalCatalog::class)->testOptions(app(OrganizationContext::class)->id()))
                        ->multiple()
                        ->searchable()
                        ->required()
                        ->helperText(__('Связь добавится к тестам на верхнем уровне. Для отдельной строки настройте причины в карточке теста.')),
                ])
                ->action(function (array $data): void {
                    $actor = Auth::user();
                    if (! $actor instanceof User) {
                        abort(403);
                    }

                    try {
                        app(ApplyMedicalCauseToTests::class)->handle(
                            $actor,
                            (int) ($data['cause_id'] ?? 0),
                            is_array($data['test_ids'] ?? null) ? $data['test_ids'] : [],
                        );
                        $this->mount();
                        Notification::make()->success()->title(__('Причина применена к выбранным тестам'))->send();
                    } catch (ValidationException $exception) {
                        $message = collect($exception->errors())->flatten()->first();
                        Notification::make()->danger()->title(is_string($message) ? $message : __('Не удалось применить причину.'))->send();
                    }
                }),
            Action::make('mergeCauses')
                ->label(__('Объединить причины'))
                ->icon('heroicon-o-arrows-pointing-in')
                ->color('gray')
                ->authorize(fn (): bool => self::canAccess())
                ->requiresConfirmation()
                ->modalHeading(__('Объединить причины'))
                ->modalDescription(__('Исторические связи будут перенесены на выбранную основную причину. Отменить объединение через интерфейс нельзя.'))
                ->form([
                    Select::make('source_id')
                        ->label(__('Причина для объединения'))
                        ->options(fn (): array => $this->causeDirectoryOptions())
                        ->searchable()
                        ->required(),
                    Select::make('target_id')
                        ->label(__('Сохранить как'))
                        ->options(fn (): array => $this->causeDirectoryOptions())
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $actor = Auth::user();
                    if (! $actor instanceof User) {
                        abort(403);
                    }

                    try {
                        app(MergeMedicalCauses::class)->handle($actor, (int) ($data['source_id'] ?? 0), (int) ($data['target_id'] ?? 0));
                        $this->mount();
                        Notification::make()->success()->title(__('Причины объединены, история сохранена'))->send();
                    } catch (ValidationException $exception) {
                        $message = collect($exception->errors())->flatten()->first();
                        Notification::make()->danger()->title(is_string($message) ? $message : __('Не удалось объединить причины.'))->send();
                    }
                }),
            Action::make('importCsv')
                ->label(__('Импортировать CSV/XLSX'))
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->authorize(fn (): bool => self::canAccess())
                ->form([
                    Select::make('section')
                        ->label(__('Раздел каталога'))
                        ->options([
                            'tests' => __('Тесты и значения'),
                            'causes' => __('Причины нарушений'),
                            'problem_tags' => __('Категории проблем'),
                        ])
                        ->required(),
                    FileUpload::make('file')
                        ->label(__('CSV/XLSX-файл'))
                        ->helperText(__('Тесты: «Название теста», «Раздел», «Значение», «Нарушение», «Активен», «Порядок теста», «Порядок значения». Причины и категории: «Название», «Группа», «Активна», «Порядок». До 2 МБ.'))
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->maxSize(2048)
                        ->storeFiles(false)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $actor = Auth::user();
                    $file = $data['file'] ?? null;
                    if (! $actor instanceof User || ! $file instanceof UploadedFile) {
                        abort(422);
                    }

                    try {
                        $rows = app(ImportMedicalClinicalCatalogCsv::class)->handle($actor, $file, (string) ($data['section'] ?? ''));
                        $this->mount();
                        Notification::make()->success()->title(__('Файл каталога импортирован'))->body(__('Импортировано строк: :count', ['count' => $rows]))->send();
                    } catch (ValidationException $exception) {
                        $message = collect($exception->errors())->flatten()->first();
                        Notification::make()->danger()->title(is_string($message) ? $message : __('Проверьте файл каталога.'))->send();
                    } catch (Throwable) {
                        Notification::make()->danger()->title(__('Файл каталога не удалось импортировать.'))->send();
                    }
                }),
        ];
    }

    public function save(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $state = $this->form->getState();
        app(SaveMedicalClinicalCatalog::class)->handle(
            $actor,
            self::withTestOrder($this->testsForSave($state, $this->selectedTestKey)),
            self::withOrder(is_array($state['causes'] ?? null) ? $state['causes'] : []),
            self::withOrder(is_array($state['problem_tags'] ?? null) ? $state['problem_tags'] : []),
            self::withOrder(is_array($state['complaints'] ?? null) ? $state['complaints'] : []),
        );
        Notification::make()->success()->title(__('Каталог сохранён'))->send();
    }

    private function hasStage1BTests(): bool
    {
        $tests = is_array($this->data['tests'] ?? null) ? $this->data['tests'] : [];

        return collect($tests)->contains(static fn (mixed $test): bool => is_array($test) && filled($test['test_type'] ?? null));
    }

    /** @return list<\Filament\Schemas\Components\Component> */
    private function catalogRowSchema(string $prefix): array
    {
        return [
            Hidden::make('key')->default(fn (): string => $prefix.'_'.Str::lower(Str::random(10))),
            TextInput::make('label')->label(__('Название строки'))->required()->maxLength(255),
            Select::make('side')
                ->label(__('Сторона'))
                ->options(['R' => 'Правая сторона', 'L' => 'Левая сторона'])
                ->nullable(),
            Select::make('input_mode')
                ->label(__('Способ ответа'))
                ->options([
                    'index' => __('Индекс'),
                    'tone' => __('Тонус'),
                    'traction' => __('Вытяжение'),
                    'finding' => __('Находка'),
                    'finding_list' => __('Список находок'),
                    'yesno' => __('Да или нет'),
                    'pain_point' => __('Болевая точка'),
                    'mobility' => __('Подвижность и угол'),
                    'visual' => __('Визуальное отклонение'),
                    'energy_chakra' => __('Энергетическая отметка'),
                    'violation_type' => __('Тип нарушения'),
                ])
                ->nullable(),
            TagsInput::make('choices')
                ->label(__('Допустимые варианты'))
                ->placeholder(__('Добавьте вариант')),
            TagsInput::make('deviation_options')
                ->label(__('Варианты отклонения'))
                ->placeholder(__('Добавьте вариант')),
            Toggle::make('angle_enabled')
                ->label(__('Показывать угол'))
                ->default(true),
            Toggle::make('index_enabled')->label(__('Показывать индекс 0–3'))->default(true),
            Select::make('cause_ids')
                ->label(__('Разрешённые причины'))
                ->options(fn (): array => $this->causeOptions)
                ->multiple()
                ->searchable()
                ->helperText(__('Пустой список допустим, если причины ещё не настроены.')),
            Hidden::make('sort_order')->dehydrated(true),
        ];
    }

    /** @return array<string, string> */
    private static function subtestOptions(mixed $subtests): array
    {
        if (! is_array($subtests)) {
            return [];
        }

        $options = [];
        foreach ($subtests as $subtest) {
            if (! is_array($subtest)) {
                continue;
            }
            $key = trim((string) ($subtest['key'] ?? ''));
            $label = trim((string) ($subtest['label'] ?? ''));
            if ($key !== '' && $label !== '') {
                $options[$key] = $key.' · '.$label;
            }
        }

        return $options;
    }

    /** @return array<string, mixed> */
    private static function testFormState(MedicalTestDefinition $test): array
    {
        $configuration = $test->catalogConfiguration();
        $links = [];
        foreach ($test->causeLinks as $link) {
            $scope = $link->scope_key === null ? '__root' : (string) $link->scope_key;
            $links[$scope][] = (int) $link->medical_cause_definition_id;
        }

        $rows = self::rowsForForm($configuration['rows'] ?? [], $links);
        $feelings = self::rowsForForm($configuration['feelings'] ?? [], $links);
        $subtests = [];
        foreach (is_array($configuration['subtests'] ?? null) ? $configuration['subtests'] : [] as $subtest) {
            if (! is_array($subtest)) {
                continue;
            }
            $key = trim((string) ($subtest['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $subtest['cause_ids'] = array_values(array_unique($links[$key] ?? []));
            $subtest['rows'] = self::rowsForForm($subtest['rows'] ?? [], $links);
            $subtest['feelings'] = self::rowsForForm($subtest['feelings'] ?? [], $links);
            $subtest['provoking_points'] = self::rowsForForm($subtest['provoking_points'] ?? [], $links);
            $subtests[] = $subtest;
        }

        $rules = is_array($configuration['rules'] ?? null) ? $configuration['rules'] : [];
        $allowedSequences = [];
        foreach (is_array($rules['allowed_sequences'] ?? null) ? $rules['allowed_sequences'] : [] as $sequence) {
            $values = is_array($sequence) && array_key_exists('values', $sequence) ? $sequence['values'] : $sequence;
            $allowedSequences[] = ['values' => is_array($values) ? array_values($values) : []];
        }
        $rules['allowed_sequences'] = $allowedSequences;

        return [
            'key' => $test->key,
            'name' => $test->name,
            'section' => $test->section,
            'test_type' => $test->test_type,
            'catalog_set' => $test->catalog_set,
            'default_in_session' => (bool) $test->default_in_session,
            'is_active' => (bool) $test->is_active,
            'sort_order' => $test->sort_order,
            'cause_ids' => array_values(array_unique($links['__root'] ?? [])),
            'rows' => $rows,
            'feelings' => $feelings,
            'subtests' => $subtests,
            'index_enabled' => array_key_exists('index_enabled', $configuration) ? (bool) $configuration['index_enabled'] : true,
            'priority_options' => is_array($configuration['priority_options'] ?? null) ? array_values($configuration['priority_options']) : [],
            'movement_options' => is_array($configuration['movement_options'] ?? null) ? array_values($configuration['movement_options']) : [],
            'tone_options' => is_array($configuration['tone_options'] ?? null) ? array_values($configuration['tone_options']) : [],
            'finding_options' => is_array($configuration['finding_options'] ?? null) ? array_values($configuration['finding_options']) : [],
            'answer_options' => is_array($configuration['answer_options'] ?? null) ? array_values($configuration['answer_options']) : [],
            'violation_types' => is_array($configuration['violation_types'] ?? null) ? array_values($configuration['violation_types']) : [],
            'default_subtests' => is_array($configuration['default_subtests'] ?? null) ? array_values($configuration['default_subtests']) : [],
            'rules' => $rules,
            'help_text' => $configuration['help_text'] ?? ($configuration['tooltip'] ?? null),
            'safety_marker_text' => $configuration['safety_marker_text'] ?? null,
            'values' => $test->values->map(static fn (MedicalTestValue $value): array => [
                'key' => $value->key,
                'label' => $value->label,
                'value_type' => $value->value_type,
                'is_abnormal' => (bool) $value->is_abnormal,
                'is_active' => (bool) $value->is_active,
                'sort_order' => $value->sort_order,
            ])->all(),
        ];
    }

    /** @param mixed $rawRows
     * @param array<string, list<int>> $links
     * @return list<array<string, mixed>>
     */
    private static function rowsForForm(mixed $rawRows, array $links): array
    {
        if (! is_array($rawRows)) {
            return [];
        }

        $rows = [];
        foreach ($rawRows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = trim((string) ($row['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $row['cause_ids'] = array_values(array_unique($links[$key] ?? []));
            $rows[] = $row;
        }

        return $rows;
    }

    private static function catalogItemLabel(mixed $value, mixed $isActive): string
    {
        $label = trim((string) $value);
        $label = $label !== '' ? $label : __('Новый элемент');

        return $label.' · '.((bool) $isActive ? __('Активен') : __('Отключён'));
    }

    /** @return array<int, string> */
    private function causeDirectoryOptions(bool $onlySelectable = false): array
    {
        return MedicalCauseDefinition::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->whereNull('merged_into_id')
            ->when($onlySelectable, static fn ($query) => $query
                ->where('is_active', true)
                ->where('moderation_status', MedicalCauseDefinition::MODERATION_ACTIVE))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'group_name', 'moderation_status', 'is_active'])
            ->mapWithKeys(static function (MedicalCauseDefinition $cause): array {
                $label = filled($cause->group_name) ? $cause->group_name.': '.$cause->name : $cause->name;
                $status = match ($cause->moderation_status) {
                    MedicalCauseDefinition::MODERATION_PENDING => __('на модерации'),
                    MedicalCauseDefinition::MODERATION_HIDDEN => __('скрыта'),
                    default => $cause->is_active ? __('активна') : __('отключена'),
                };

                return [(int) $cause->getKey() => $label.' · '.$status];
            })
            ->all();
    }

    /** @param array<string, mixed> $state */
    private static function catalogCauseItemLabel(array $state): string
    {
        $label = trim((string) ($state['name'] ?? ''));
        $label = $label !== '' ? $label : __('Новая причина');
        $status = match ((string) ($state['moderation_status'] ?? MedicalCauseDefinition::MODERATION_ACTIVE)) {
            MedicalCauseDefinition::MODERATION_PENDING => __('на модерации'),
            MedicalCauseDefinition::MODERATION_HIDDEN => __('скрыта'),
            default => (bool) ($state['is_active'] ?? true) ? __('активна') : __('отключена'),
        };

        return $label.' · '.$status;
    }

    private static function withTestOrder(array $rows): array
    {
        return array_values(array_map(static function (mixed $row, int $index): mixed {
            if (! is_array($row)) {
                return $row;
            }

            $row['sort_order'] = $index;
            $row['values'] = self::withOrder(is_array($row['values'] ?? null) ? $row['values'] : []);
            $row['rows'] = self::withOrder(is_array($row['rows'] ?? null) ? $row['rows'] : []);
            $row['feelings'] = self::withOrder(is_array($row['feelings'] ?? null) ? $row['feelings'] : []);
            $subtests = is_array($row['subtests'] ?? null) ? array_values($row['subtests']) : [];
            $row['subtests'] = array_values(array_map(static function (mixed $subtest, int $subtestIndex): mixed {
                if (! is_array($subtest)) {
                    return $subtest;
                }

                $subtest['sort_order'] = $subtestIndex;
                $subtest['rows'] = self::withOrder(is_array($subtest['rows'] ?? null) ? $subtest['rows'] : []);
                $subtest['provoking_points'] = self::withOrder(is_array($subtest['provoking_points'] ?? null) ? $subtest['provoking_points'] : []);
                $subtest['feelings'] = self::withOrder(is_array($subtest['feelings'] ?? null) ? $subtest['feelings'] : []);

                return $subtest;
            }, $subtests, array_keys($subtests)));

            return $row;
        }, array_values($rows), array_keys(array_values($rows))));
    }

    private static function withOrder(array $rows): array
    {
        return array_values(array_map(static function (mixed $row, int $index): mixed {
            if (is_array($row)) {
                $row['sort_order'] = $index;
            }

            return $row;
        }, array_values($rows), array_keys(array_values($rows))));
    }
}
