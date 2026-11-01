<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\Analytics\Application\AcquisitionAnalytics;
use App\Modules\Analytics\Application\AnalyticsClientGroupCatalog;
use App\Modules\Analytics\Application\AnalyticsMetricConfiguration;
use App\Modules\Analytics\Application\AnalyticsMetricEvaluator;
use App\Modules\Analytics\Application\AnalyticsReportExport;
use App\Modules\Analytics\Application\BookingStatusAnalytics;
use App\Modules\Analytics\Application\ClientAgeRangeCatalog;
use App\Modules\Analytics\Application\ClientCohortQuery;
use App\Modules\Analytics\Application\ClientSegmentAnalytics;
use App\Modules\Analytics\Application\ClientSessionAxisCatalog;
use App\Modules\Analytics\Application\Data\AnalyticsMetricFilters;
use App\Modules\Analytics\Application\Data\ClientCohortFilters;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\DemographicsAnalytics;
use App\Modules\Analytics\Application\FeedbackAnalytics;
use App\Modules\Analytics\Application\FinanceAnalytics;
use App\Modules\Analytics\Application\GoalAnalytics;
use App\Modules\Analytics\Application\PainAnalytics;
use App\Modules\Analytics\Application\PurchaseAnalytics;
use App\Modules\Analytics\Application\PurchaseGroupCatalog;
use App\Modules\Analytics\Application\SchedulingAnalytics;
use App\Modules\Analytics\Application\ServiceVisitAnalytics;
use App\Modules\Analytics\Domain\Enums\ClientSegment;
use App\Modules\Analytics\Domain\Enums\PainScoreBasis;
use App\Modules\Attribution\Application\AttributionSourceCatalog;
use App\Modules\Feedback\Application\FeedbackThemeCatalog;
use App\Modules\Finance\Application\FinancialReconciliationContract;
use App\Modules\Identity\Application\ClientSearch;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\MedicalProblemTagCatalog;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use App\Modules\Tracker\Application\TrackerGoalTagCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use UnitEnum;

/** @property-read Schema $form */
final class AnalyticsConsole extends LocalizedPage
{
    protected static ?string $title = 'Консоль аналитики';

    protected static ?string $navigationLabel = 'Консоль аналитики';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Аналитика';

    protected static ?int $navigationSort = 2;

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    /** @var array<string, mixed> */
    public array $results = [];

    /** @var array<string, string> */
    public array $dimensionErrors = [];

    public ?int $cohortPreviewCount = null;

    /** @var list<array<string, mixed>> */
    public array $cohortClients = [];

    public ?string $cohortDrilldownReason = null;

    protected string $view = 'filament.pages.analytics-console';

    public static function canAccess(): bool
    {
        $actor = Auth::user();
        if (! $actor instanceof User) {
            return false;
        }

        try {
            return app(OrganizationAuthorizer::class)->allows(
                $actor,
                app(OrganizationContext::class)->organization(),
                OrganizationPermission::ViewClients,
            );
        } catch (LogicException) {
            return false;
        }
    }

    public function mount(): void
    {
        $defaults = [
            'dimensions' => ['pain', 'custom_metrics'],
            'cohort_client_ids' => [],
            'cohort_exclude_client_ids' => [],
            'cohort_saved_group_ids' => [],
            'cohort_segments' => [],
            'cohort_archived' => null,
            'cohort_blacklist' => null,
            'cohort_problem_tag_ids' => [],
            'cohort_problem_tag_mode' => 'any',
            'cohort_feedback_theme_ids' => [],
            'cohort_goal_tag_ids' => [],
            'cohort_age_min' => null,
            'cohort_age_max' => null,
            'cohort_age_range' => null,
            'cohort_genders' => [],
            'cohort_children' => null,
            'cohort_countries' => [],
            'cohort_source_keys' => [],
            'cohort_service_ids' => [],
            'cohort_service_categories' => [],
            'cohort_specialist_ids' => [],
            'cohort_booking_statuses' => [],
            'cohort_booking_event_types' => [],
            'cohort_completed_visits_min' => null,
            'cohort_completed_visits_max' => null,
            'cohort_last_visit_from' => null,
            'cohort_last_visit_to' => null,
            'cohort_purchase_types' => [],
            'cohort_purchase_statuses' => [],
            'cohort_pain_score_basis' => PainScoreBasis::Any->value,
            'cohort_has_pain_score' => null,
            'cohort_pain_score_min' => null,
            'cohort_pain_score_max' => null,
            'cohort_satisfaction_min' => null,
            'cohort_satisfaction_max' => null,
            'client_id' => null,
            'problem_tag_id' => null,
            'feedback_theme_id' => null,
            'goal_tag_id' => null,
            'source_key' => null,
            'purchase_type' => null,
            'booking_status' => null,
            'period' => DashboardPeriod::DefaultPreset,
            'start_date' => null,
            'end_date' => null,
        ];
        $saved = session()->get($this->stateSessionKey(), []);
        $this->form->fill([...$defaults, ...(is_array($saved) ? $saved : [])]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Кого анализируем'))
                    ->schema([
                        Select::make('cohort_client_ids')
                            ->label(__('Конкретные клиенты'))
                            ->multiple()
                            ->searchable()
                            ->optionsLimit(ClientSearch::MAX_RESULTS)
                            ->getSearchResultsUsing(fn (string $search): array => $this->clientSearchResults($search))
                            ->getOptionLabelsUsing(fn (array $values): array => array_reduce($values, function (array $labels, mixed $value): array {
                                $label = $this->clientOptionLabel($value);
                                if ($label !== null) {
                                    $labels[(string) $value] = $label;
                                }

                                return $labels;
                            }, []))
                            ->live()
                            ->placeholder(__('Все клиенты')),
                        Select::make('cohort_exclude_client_ids')
                            ->label(__('Исключить клиентов'))
                            ->multiple()
                            ->searchable()
                            ->optionsLimit(ClientSearch::MAX_RESULTS)
                            ->getSearchResultsUsing(fn (string $search): array => $this->clientSearchResults($search))
                            ->getOptionLabelsUsing(fn (array $values): array => array_reduce($values, function (array $labels, mixed $value): array {
                                $label = $this->clientOptionLabel($value);
                                if ($label !== null) {
                                    $labels[(string) $value] = $label;
                                }

                                return $labels;
                            }, []))
                            ->live(),
                        Select::make('cohort_saved_group_ids')
                            ->label(__('Сохранённые группы клиентов'))
                            ->multiple()
                            ->options(fn (): array => app(AnalyticsClientGroupCatalog::class)->options())
                            ->searchable()
                            ->preload()
                            ->live(),
                        Select::make('cohort_segments')
                            ->label(__('Сохранённые категории клиентов'))
                            ->multiple()
                            ->options(ClientSegment::labels())
                            ->live(),
                        Select::make('cohort_archived')
                            ->label(__('Архивированность'))
                            ->options(['0' => 'Только активные', '1' => 'Только архивные'])
                            ->live()
                            ->nullable(),
                        Select::make('cohort_blacklist')
                            ->label(__('Чёрный список'))
                            ->options(['0' => 'Не в чёрном списке', '1' => 'В чёрном списке'])
                            ->live()
                            ->nullable(),
                        Select::make('cohort_problem_tag_ids')
                            ->label(__('Категории проблем'))
                            ->multiple()
                            ->options(fn (): array => app(MedicalProblemTagCatalog::class)->options(app(OrganizationContext::class)->id()))
                            ->searchable()
                            ->preload()
                            ->live(),
                        Select::make('cohort_problem_tag_mode')
                            ->label(__('Категории проблем: режим'))
                            ->options(['any' => 'Хотя бы одна', 'all' => 'Все выбранные'])
                            ->default('any')
                            ->live(),
                        Select::make('cohort_exclude_problem_tag_ids')
                            ->label(__('Исключить категории проблем'))
                            ->multiple()
                            ->options(fn (): array => app(MedicalProblemTagCatalog::class)->options(app(OrganizationContext::class)->id()))
                            ->searchable()
                            ->preload()
                            ->live(),
                        Select::make('cohort_feedback_theme_ids')
                            ->label(__('Темы обратной связи'))
                            ->multiple()
                            ->options(fn (): array => app(FeedbackThemeCatalog::class)->options(app(OrganizationContext::class)->id()))
                            ->searchable()
                            ->preload()
                            ->live(),
                        Select::make('cohort_goal_tag_ids')
                            ->label(__('Категории целей'))
                            ->multiple()
                            ->options(fn (): array => app(TrackerGoalTagCatalog::class)->options(app(OrganizationContext::class)->id()))
                            ->searchable()
                            ->preload()
                            ->live(),
                        Select::make('cohort_genders')
                            ->label(__('Пол'))
                            ->multiple()
                            ->options(['MALE' => 'Мужчины', 'FEMALE' => 'Женщины'])
                            ->live(),
                        Select::make('cohort_children')
                            ->label(__('Возрастная группа'))
                            ->options(['1' => 'Дети', '0' => 'Взрослые'])
                            ->live()
                            ->nullable(),
                        TextInput::make('cohort_age_min')
                            ->label(__('Возраст от'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(150)
                            ->nullable(),
                        TextInput::make('cohort_age_max')
                            ->label(__('Возраст до'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(150)
                            ->nullable(),
                        Select::make('cohort_age_range')
                            ->label(__('Настроенный возрастной диапазон'))
                            ->options(fn (): array => app(ClientAgeRangeCatalog::class)->options(app(OrganizationContext::class)->id()))
                            ->searchable()
                            ->nullable()
                            ->helperText(__('Диапазоны настраиваются в разделе «Возрастные диапазоны».')),
                        Select::make('cohort_countries')
                            ->label(__('Страны'))
                            ->multiple()
                            ->options(fn (): array => $this->countryOptions())
                            ->searchable()
                            ->live(),
                        Select::make('cohort_source_keys')
                            ->label(__('Источники привлечения'))
                            ->multiple()
                            ->options(fn (): array => app(AttributionSourceCatalog::class)->optionsForSelection(app(OrganizationContext::class)->id()))
                            ->searchable()
                            ->preload()
                            ->live(),
                        Select::make('cohort_service_ids')
                            ->label(__('Услуги'))
                            ->multiple()
                            ->options(fn (): array => Service::query()->where('organization_id', app(OrganizationContext::class)->id())->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload()
                            ->live(),
                        Select::make('cohort_service_categories')
                            ->label(__('Группы услуг'))
                            ->multiple()
                            ->options(fn (): array => Service::query()
                                ->where('organization_id', app(OrganizationContext::class)->id())
                                ->whereNotNull('category')
                                ->where('category', '!=', '')
                                ->distinct()
                                ->orderBy('category')
                                ->pluck('category', 'category')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->live()
                            ->helperText(__('Группа берётся из настраиваемого поля «Категория» услуги.')),
                        Select::make('cohort_specialist_ids')
                            ->label(__('Специалисты'))
                            ->multiple()
                            ->options(fn (): array => Specialist::query()->where('organization_id', app(OrganizationContext::class)->id())->orderBy('display_name')->pluck('display_name', 'id')->all())
                            ->searchable()
                            ->preload()
                            ->live(),
                        Select::make('cohort_booking_statuses')
                            ->label(__('Статусы записей'))
                            ->multiple()
                            ->options([
                                BookingStatus::Requested->value => 'Запрошен',
                                BookingStatus::PendingReview->value => 'На проверке',
                                BookingStatus::Completed->value => 'Выполнен',
                                BookingStatus::Confirmed->value => 'Подтверждён',
                                BookingStatus::Rejected->value => 'Отклонён',
                                BookingStatus::Cancelled->value => 'Отменён',
                                BookingStatus::NoShow->value => 'Неявка',
                            ])
                            ->live(),
                        Select::make('cohort_booking_event_types')
                            ->label(__('Статусы истории записей'))
                            ->multiple()
                            ->options(BookingStatusAnalytics::options())
                            ->live()
                            ->helperText(__('Фильтр использует историю событий записи, включая переносы.')),
                        TextInput::make('cohort_completed_visits_min')
                            ->label(__('Завершённых визитов от'))
                            ->integer()
                            ->minValue(0)
                            ->nullable(),
                        TextInput::make('cohort_completed_visits_max')
                            ->label(__('Завершённых визитов до'))
                            ->integer()
                            ->minValue(0)
                            ->nullable(),
                        DatePicker::make('cohort_last_visit_from')
                            ->label(__('Последний визит от'))
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->nullable(),
                        DatePicker::make('cohort_last_visit_to')
                            ->label(__('Последний визит до'))
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->nullable(),
                        Select::make('cohort_purchase_types')
                            ->label(__('Типы покупок'))
                            ->multiple()
                            ->options(fn (): array => app(PurchaseGroupCatalog::class)->options(app(OrganizationContext::class)->id()))
                            ->live(),
                        Select::make('cohort_purchase_statuses')
                            ->label(__('Статусы покупок'))
                            ->multiple()
                            ->options(['paid' => 'Оплачена', 'pending_payment' => 'Ожидает оплаты', 'refunded' => 'Возвращена'])
                            ->live(),
                        Select::make('cohort_has_pain_score')
                            ->label(__('Оценка боли'))
                            ->options(['1' => 'Есть оценка', '0' => 'Без оценки'])
                            ->live()
                            ->nullable(),
                        Select::make('cohort_pain_score_basis')
                            ->label(__('Источник оценки боли'))
                            ->options(PainScoreBasis::labels())
                            ->default(PainScoreBasis::Any->value)
                            ->live(),
                        Select::make('cohort_pain_score_min')
                            ->label(__('Оценка боли от'))
                            ->options(self::scoreOptions())
                            ->live()
                            ->nullable(),
                        Select::make('cohort_pain_score_max')
                            ->label(__('Оценка боли до'))
                            ->options(self::scoreOptions())
                            ->live()
                            ->nullable(),
                        Select::make('cohort_satisfaction_min')
                            ->label(__('Удовлетворённость от'))
                            ->options(self::scoreOptions())
                            ->live()
                            ->nullable(),
                        Select::make('cohort_satisfaction_max')
                            ->label(__('Удовлетворённость до'))
                            ->options(self::scoreOptions())
                            ->live()
                            ->nullable(),
                        Placeholder::make('cohort_preview')
                            ->label(__('Предпросмотр'))
                            ->content(fn (Get $get): string => 'Найдено клиентов: '.$this->previewCountForState($get)),
                    ])
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                    ->columnSpanFull(),
                Section::make(__('Что считаем'))
                    ->schema([
                        CheckboxList::make('dimensions')
                            ->label(__('Показатели'))
                            ->options([
                                'pain' => __('Проблемы и динамика боли'),
                                'acquisition' => __('Источники клиентов'),
                                'scheduling' => __('Записи и визиты'),
                                'finance' => __('Финансы'),
                                'feedback' => __('Удовлетворённость и обратная связь'),
                                'goals' => __('Цели и задачи'),
                                'demographics' => __('Демография клиентов'),
                                'services' => __('Услуги и визиты'),
                                'purchases' => __('Типы покупок'),
                                'booking_statuses' => __('Статусы записей'),
                                'segments' => __('Категории клиентов'),
                                'custom_metrics' => __('Вычисляемые метрики'),
                            ])
                            ->live()
                            ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                            ->required()
                            ->default(['pain']),
                        Select::make('client_id')
                            ->label(__('Клиент'))
                            ->options([])
                            ->searchable()
                            ->optionsLimit(ClientSearch::MAX_RESULTS)
                            ->getSearchResultsUsing(fn (string $search): array => $this->clientSearchResults($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => $this->clientOptionLabel($value))
                            ->nullable()
                            ->placeholder(__('Все клиенты')),
                        Select::make('problem_tag_id')
                            ->label(__('Категория проблемы'))
                            ->options(function (Get $get): array {
                                $selectedTagId = filter_var(
                                    $get('problem_tag_id'),
                                    FILTER_VALIDATE_INT,
                                    ['options' => ['min_range' => 1]],
                                );

                                return app(MedicalProblemTagCatalog::class)->optionsForSelection(
                                    app(OrganizationContext::class)->id(),
                                    $selectedTagId === false ? [] : [(int) $selectedTagId],
                                );
                            })
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->placeholder(__('Все категории'))
                            ->visible(fn (Get $get): bool => in_array('pain', (array) $get('dimensions'), true)),
                        Select::make('feedback_theme_id')
                            ->label(__('Тема обратной связи'))
                            ->options(function (Get $get): array {
                                $selectedThemeId = filter_var(
                                    $get('feedback_theme_id'),
                                    FILTER_VALIDATE_INT,
                                    ['options' => ['min_range' => 1]],
                                );

                                return app(FeedbackThemeCatalog::class)->optionsForSelection(
                                    app(OrganizationContext::class)->id(),
                                    $selectedThemeId === false ? [] : [(int) $selectedThemeId],
                                );
                            })
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->placeholder(__('Все темы'))
                            ->visible(fn (Get $get): bool => in_array('feedback', (array) $get('dimensions'), true)),
                        Select::make('goal_tag_id')
                            ->label(__('Категория задачи'))
                            ->options(function (Get $get): array {
                                $selectedGoalTagId = filter_var(
                                    $get('goal_tag_id'),
                                    FILTER_VALIDATE_INT,
                                    ['options' => ['min_range' => 1]],
                                );

                                return app(TrackerGoalTagCatalog::class)->optionsForSelection(
                                    app(OrganizationContext::class)->id(),
                                    $selectedGoalTagId === false ? [] : [(int) $selectedGoalTagId],
                                );
                            })
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->placeholder(__('Все категории'))
                            ->visible(fn (Get $get): bool => in_array('goals', (array) $get('dimensions'), true)),
                        Select::make('source_key')
                            ->label(__('Источник клиента'))
                            ->options(function (Get $get): array {
                                $selectedSourceKey = $get('source_key');

                                return app(AttributionSourceCatalog::class)->optionsForSelection(
                                    app(OrganizationContext::class)->id(),
                                    is_string($selectedSourceKey) ? $selectedSourceKey : null,
                                );
                            })
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->placeholder(__('Все источники'))
                            ->visible(fn (Get $get): bool => in_array('acquisition', (array) $get('dimensions'), true)),
                        Select::make('purchase_type')
                            ->label(__('Тип покупки'))
                            ->options(fn (): array => app(PurchaseGroupCatalog::class)->options(app(OrganizationContext::class)->id()))
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->placeholder(__('Все типы покупок'))
                            ->visible(fn (Get $get): bool => in_array('purchases', (array) $get('dimensions'), true)),
                        Select::make('booking_status')
                            ->label(__('Статус записи'))
                            ->options(BookingStatusAnalytics::options())
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->placeholder(__('Все статусы'))
                            ->visible(fn (Get $get): bool => in_array('booking_statuses', (array) $get('dimensions'), true)),
                    ])
                    ->columns(['default' => 1, 'sm' => 2])
                    ->columnSpanFull(),
                Section::make(__('Период и правила времени'))
                    ->description(__('Период применяется к датам событий выбранных показателей. Сегменты показывают текущее состояние на момент анализа.'))
                    ->schema([
                        Select::make('period')
                            ->label(__('Период'))
                            ->options(DashboardPeriod::options())
                            ->required()
                            ->live(),
                        DatePicker::make('start_date')
                            ->label(__('Начало'))
                            ->format('Y-m-d')
                            ->displayFormat('d.m.Y')
                            ->requiredIf('period', DashboardPeriod::Custom)
                            ->validationMessages([
                                'required_if' => __('Укажите дату начала произвольного периода.'),
                            ])
                            ->visible(fn (Get $get): bool => $get('period') === DashboardPeriod::Custom),
                        DatePicker::make('end_date')
                            ->label(__('Конец'))
                            ->format('Y-m-d')
                            ->displayFormat('d.m.Y')
                            ->requiredIf('period', DashboardPeriod::Custom)
                            ->validationMessages([
                                'required_if' => __('Укажите дату окончания произвольного периода.'),
                            ])
                            ->visible(fn (Get $get): bool => $get('period') === DashboardPeriod::Custom),
                    ])
                    ->columns(['default' => 1, 'sm' => 2])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('analytics-console-form')
            ->extraAttributes(['novalidate' => true])
            ->livewireSubmitHandler('analyze')
            ->footer([
                Actions::make([
                    Action::make('analyze')->label(__('Анализировать'))->submit('analyze'),
                ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('Скачать Excel'))
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn (): ?StreamedResponse => $this->export()),
        ];
    }

    public function analyze(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $this->dimensionErrors = [];
        $this->cohortClients = [];
        $this->cohortDrilldownReason = null;
        $this->resetValidation();
        $resolved = $this->validatedStateAndPeriod('анализ');
        if ($resolved === null) {
            return;
        }
        [$state, $period] = $resolved;
        $this->cohortPreviewCount = $this->previewCountForState($state, $period);
        session()->put($this->stateSessionKey(), $this->persistedState($state));
        $calculated = $this->calculateDimensions($actor, $state, $period);
        $this->results = $calculated['results'];
        $this->dimensionErrors = $calculated['errors'];

        if ($this->dimensionErrors !== []) {
            Notification::make()
                ->title(__('Анализ завершён частично'))
                ->body(__('Часть выбранных показателей недоступна. Проверьте список разделов и повторите анализ.'))
                ->warning()
                ->send();

            return;
        }

        $this->cohortDrilldownReason = $this->cohortReason($state);
        $this->cohortClients = $this->cohortDisplayRows($actor, $state, $period);
        Notification::make()->title(__('Анализ обновлён'))->success()->send();
    }

    public function showCohortClients(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $resolved = $this->validatedStateAndPeriod('просмотр клиентов');
        if ($resolved === null) {
            return;
        }
        [$state, $period] = $resolved;
        $this->cohortPreviewCount = $this->previewCountForState($state, $period);
        $this->cohortDrilldownReason = $this->cohortReason($state);
        $this->cohortClients = $this->cohortDisplayRows($actor, $state, $period);
    }

    public function showAggregateClients(string $dimension, string|int $value): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $resolved = $this->validatedStateAndPeriod('просмотр клиентов');
        if ($resolved === null) {
            return;
        }

        [$state, $period] = $resolved;
        try {
            $state = $this->stateForAggregate($state, $dimension, $value);
        } catch (\InvalidArgumentException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }
        $this->cohortPreviewCount = $this->previewCountForState($state, $period);
        $this->cohortDrilldownReason = $this->cohortReason($state);
        $this->cohortClients = $this->cohortDisplayRows($actor, $state, $period);
    }

    public function export(): ?StreamedResponse
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $this->dimensionErrors = [];
        $this->cohortClients = [];
        $this->resetValidation();
        $resolved = $this->validatedStateAndPeriod('экспорт');
        if ($resolved === null) {
            return null;
        }
        [$state, $period] = $resolved;
        $this->cohortPreviewCount = $this->previewCountForState($state, $period);
        $calculated = $this->calculateDimensions($actor, $state, $period);
        $this->results = $calculated['results'];
        $this->dimensionErrors = $calculated['errors'];

        if ($this->dimensionErrors !== []) {
            Notification::make()
                ->title(__('Excel не сформирован'))
                ->body(__('Недоступны: ').implode(', ', array_values($this->dimensionErrors)))
                ->danger()
                ->send();

            return null;
        }

        $this->cohortClients = $this->cohortDisplayRows($actor, $state, $period);
        $client = $this->selectedClient($state);
        $exportResults = $this->results;
        $currency = is_array($exportResults['finance'] ?? null)
            ? ($exportResults['finance']['currency'] ?? null)
            : null;

        return app(AnalyticsReportExport::class)->download(
            $actor,
            $exportResults,
            'analytics-'.$period->startDate.'-'.$period->endDate.'.xlsx',
            [
                'period' => $period->label(),
                'client' => $client === null ? 'Все клиенты' : $client->full_name,
                'filters' => $this->exportFilterLabels($state),
                'cohort_filter_labels' => $this->cohortFilterLabels($state),
                'timezone' => $period->timezone,
                'currency' => $currency,
                'date_basis' => 'Дата события соответствующего показателя',
                'segment_state' => 'Текущее состояние сегментов на момент анализа',
                'cohort_count' => $this->cohortPreviewCount,
                'session_axis' => $exportResults['pain']['session_axis_label'] ?? null,
                'cohort_clients' => $this->cohortRows($actor, $state, $period),
                'cohort_filters' => $this->cohortFilters($state)->toArray(),
                'rule_versions' => [
                    ...$this->cohortRuleVersions($state),
                    'client_session_axis' => $exportResults['pain']['session_axis_version']
                        ?? app(ClientSessionAxisCatalog::class)->selected(app(OrganizationContext::class)->id())['version'],
                    ...$this->metricRuleVersions($state),
                ],
                'generated_at' => now()->toIso8601String(),
            ],
        );
    }

    /** @return array{0: array<string, mixed>, 1: DashboardPeriod}|null */
    private function validatedStateAndPeriod(string $operation): ?array
    {
        try {
            $state = $this->form->getState();
            $organization = app(OrganizationContext::class)->organization();
            $period = DashboardPeriod::fromFilters($state, $organization->defaultTimezone());
            $this->cohortFilters($state);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $field = str_starts_with($field, 'data.') ? $field : 'data.'.$field;
                foreach ((array) $messages as $message) {
                    $this->addError($field, (string) $message);
                }
            }
            Notification::make()
                ->title(__('Не удалось выполнить :operation', ['operation' => $operation]))
                ->body(__('Проверьте даты произвольного периода.'))
                ->danger()
                ->send();

            return null;
        } catch (\InvalidArgumentException $exception) {
            $this->addError('data.cohort_filters', $exception->getMessage());
            Notification::make()
                ->title(__('Не удалось выполнить :operation', ['operation' => $operation]))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        }

        return [$state, $period];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{results: array<string, mixed>, errors: array<string, string>}
     */
    private function calculateDimensions(User $actor, array $state, DashboardPeriod $period): array
    {
        $dimensions = is_array($state['dimensions'] ?? null) ? array_values($state['dimensions']) : [];
        $client = $this->selectedClient($state);
        $cohortFilters = $this->cohortFilters($state);
        $problemTagId = $this->selectedProblemTagId($state);
        $feedbackThemeId = $this->selectedFeedbackThemeId($state);
        $goalTagId = $this->selectedGoalTagId($state);
        $sourceKey = $this->selectedSourceKey($state);
        $purchaseType = $this->selectedPurchaseType($state);
        $bookingStatus = $this->selectedBookingStatus($state);
        $results = [];
        $errors = [];

        foreach ($dimensions as $dimension) {
            $key = (string) $dimension;
            try {
                $results[$key] = match ($key) {
                    'pain' => app(PainAnalytics::class)->handle($actor, $period, $client, $problemTagId, $cohortFilters)->toArray(),
                    'acquisition' => $this->acquisition($actor, $period, $client, $sourceKey, $cohortFilters),
                    'scheduling' => $this->scheduling($actor, $period, $client, $cohortFilters),
                    'finance' => $this->finance($actor, $period, $client, $cohortFilters),
                    'feedback' => $this->feedback($actor, $period, $client, $feedbackThemeId, $cohortFilters),
                    'goals' => $this->goals($actor, $period, $client, $goalTagId, $cohortFilters),
                    'demographics' => $this->demographics($actor, $period, $client, $cohortFilters),
                    'services' => $this->services($actor, $period, $client, $cohortFilters),
                    'purchases' => $this->purchases($actor, $period, $client, $purchaseType, $cohortFilters),
                    'booking_statuses' => $this->bookingStatuses($actor, $period, $client, $bookingStatus, $cohortFilters),
                    'segments' => app(ClientSegmentAnalytics::class)->handle($actor, $client, $cohortFilters, $period),
                    'custom_metrics' => $this->customMetrics($actor, $period, $client, $state, $cohortFilters),
                    default => null,
                };
            } catch (Throwable $exception) {
                $errors[$key] = $this->dimensionLabel($key);
                Log::warning('analytics.dimension_failed', [
                    'organization_id' => app(OrganizationContext::class)->id(),
                    'dimension' => $key,
                    'request_id' => request()->header('X-Request-Id'),
                    'route' => request()->route()?->getName(),
                    'exception' => $exception::class,
                ]);
            }
        }

        return [
            'results' => array_filter($results, static fn (mixed $result): bool => is_array($result)),
            'errors' => $errors,
        ];
    }

    /** @return array<string, mixed> */
    private function acquisition(User $actor, DashboardPeriod $period, ?Client $client = null, ?string $sourceKey = null, ?ClientCohortFilters $cohortFilters = null): array
    {
        $data = app(AcquisitionAnalytics::class)->handle($actor, $period, $client, $sourceKey, $cohortFilters);

        return [
            'new_clients' => $data->newClients,
            'sources' => array_map(static fn ($source): array => [
                'key' => $source->key,
                'label' => $source->label,
                'count' => $source->count,
            ], $data->sources),
        ];
    }

    /** @return array<string, mixed> */
    private function scheduling(User $actor, DashboardPeriod $period, ?Client $client = null, ?ClientCohortFilters $cohortFilters = null): array
    {
        $data = app(SchedulingAnalytics::class)->handle($actor, $period, $client, $cohortFilters);

        return [
            'bookings' => $data->bookings,
            'visits' => $data->visits,
            'cancellations' => $data->cancellations,
            'reschedules' => $data->reschedules,
            'no_shows' => $data->noShows,
        ];
    }

    /** @return array<string, mixed> */
    private function finance(User $actor, DashboardPeriod $period, ?Client $client = null, ?ClientCohortFilters $cohortFilters = null): array
    {
        $data = app(FinanceAnalytics::class)->handle($actor, $period, $client, $cohortFilters);
        $accounts = array_map(function (array $account): array {
            $account['movement_display'] = $this->formatFinanceAmount(
                $account['movement_minor'],
                $account['movement_currency'],
            );

            return $account;
        }, $data->accounts);

        return [
            'available' => $data->available,
            'currency' => $data->baseCurrency,
            'revenue_minor' => $data->revenueMinor,
            'revenue_display' => $this->formatFinanceAmount($data->revenueMinor, $data->baseCurrency),
            'receipt_count' => $data->receiptCount,
            'average_receipt_minor' => $data->averageReceiptMinor,
            'average_receipt_display' => $this->formatFinanceAmount($data->averageReceiptMinor, $data->baseCurrency),
            'debt_minor' => $data->debtMinor,
            'debt_display' => $this->formatFinanceAmount($data->debtMinor, $data->baseCurrency),
            'realized_ltv_minor' => $data->realizedLtvMinor,
            'realized_ltv_display' => $this->formatFinanceAmount($data->realizedLtvMinor, $data->baseCurrency),
            'expenses_minor' => $data->expensesMinor,
            'expenses_display' => $this->formatFinanceAmount($data->expensesMinor, $data->baseCurrency),
            'cash_movement_minor' => $data->cashMovementMinor,
            'cash_movement_display' => $this->formatFinanceAmount($data->cashMovementMinor, $data->baseCurrency),
            'accounts' => $accounts,
        ];
    }

    private function formatFinanceAmount(?string $minor, string $currency): string
    {
        if ($minor === null || $currency === '') {
            return '—';
        }

        try {
            $contract = app(FinancialReconciliationContract::class);
            $code = $contract->currency($currency);

            return $contract->money($minor, $code, 'A persisted financial amount is invalid.')
                ->toDecimalString().' '.$code->value;
        } catch (Throwable) {
            return '—';
        }
    }

    /** @return array<string, mixed> */
    private function feedback(User $actor, DashboardPeriod $period, ?Client $client = null, ?int $themeId = null, ?ClientCohortFilters $cohortFilters = null): array
    {
        return app(FeedbackAnalytics::class)->handle($actor, $period, $client, $themeId, $cohortFilters)->toArray();
    }

    /** @return array<string, mixed> */
    private function goals(User $actor, DashboardPeriod $period, ?Client $client = null, ?int $goalTagId = null, ?ClientCohortFilters $cohortFilters = null): array
    {
        return app(GoalAnalytics::class)->handle($actor, $period, $client, $goalTagId, $cohortFilters)->toArray();
    }

    /** @return array<string, mixed> */
    private function demographics(User $actor, DashboardPeriod $period, ?Client $client = null, ?ClientCohortFilters $cohortFilters = null): array
    {
        return app(DemographicsAnalytics::class)->handle($actor, $period, $client, $cohortFilters)->toArray();
    }

    /** @return array<string, mixed> */
    private function services(User $actor, DashboardPeriod $period, ?Client $client = null, ?ClientCohortFilters $cohortFilters = null): array
    {
        return app(ServiceVisitAnalytics::class)->handle($actor, $period, $client, $cohortFilters)->toArray();
    }

    /** @return array<string, mixed> */
    private function purchases(User $actor, DashboardPeriod $period, ?Client $client = null, ?string $catalogType = null, ?ClientCohortFilters $cohortFilters = null): array
    {
        return app(PurchaseAnalytics::class)->handle($actor, $period, $client, $catalogType, $cohortFilters)->toArray();
    }

    /** @return array<string, mixed> */
    private function bookingStatuses(User $actor, DashboardPeriod $period, ?Client $client = null, ?string $status = null, ?ClientCohortFilters $cohortFilters = null): array
    {
        return app(BookingStatusAnalytics::class)->handle($actor, $period, $client, $status, $cohortFilters)->toArray();
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{metrics: list<array<string, mixed>>}
     */
    private function customMetrics(User $actor, DashboardPeriod $period, ?Client $client, array $state, ?ClientCohortFilters $cohortFilters = null): array
    {
        $metrics = app(AnalyticsMetricConfiguration::class)->metrics(true);
        $filters = new AnalyticsMetricFilters(
            problemTagId: $this->selectedProblemTagId($state),
            feedbackThemeId: $this->selectedFeedbackThemeId($state),
            goalTagId: $this->selectedGoalTagId($state),
            sourceKey: $this->selectedSourceKey($state),
            purchaseType: $this->selectedPurchaseType($state),
            bookingStatus: $this->selectedBookingStatus($state),
            cohort: $cohortFilters,
        );
        $results = app(AnalyticsMetricEvaluator::class)->evaluate($actor, $period, $metrics, $client, $filters);

        return [
            'metrics' => array_values(array_map(
                static fn ($result): array => $result->toArray(),
                $results,
            )),
        ];
    }

    private function dimensionLabel(string $dimension): string
    {
        return [
            'pain' => __('Проблемы и динамика боли'),
            'acquisition' => __('Источники клиентов'),
            'scheduling' => __('Записи и визиты'),
            'finance' => __('Финансы'),
            'feedback' => __('Удовлетворённость и обратная связь'),
            'goals' => __('Цели и задачи'),
            'demographics' => __('Демография клиентов'),
            'services' => __('Услуги и визиты'),
            'purchases' => __('Типы покупок'),
            'booking_statuses' => __('Статусы записей'),
            'segments' => __('Категории клиентов'),
            'custom_metrics' => __('Вычисляемые метрики'),
        ][$dimension] ?? __('Выбранный показатель');
    }

    /** @param array<string, mixed> $state
     * @return list<string>
     */
    private function exportFilterLabels(array $state): array
    {
        $filters = [
            'Показатели: '.implode(', ', array_map(fn (mixed $dimension): string => $this->dimensionLabel((string) $dimension), (array) ($state['dimensions'] ?? []))),
            'Клиентская когорта: '.$this->cohortReason($state),
        ];

        $organizationId = app(OrganizationContext::class)->id();
        $problemTagId = $this->selectedProblemTagId($state);
        if ($problemTagId !== null) {
            $label = $this->optionLabel(
                app(MedicalProblemTagCatalog::class)->optionsForSelection($organizationId, [$problemTagId]),
                $problemTagId,
            );
            $filters[] = 'Категория проблемы: '.$label;
        }

        $feedbackThemeId = $this->selectedFeedbackThemeId($state);
        if ($feedbackThemeId !== null) {
            $label = $this->optionLabel(
                app(FeedbackThemeCatalog::class)->optionsForSelection($organizationId, [$feedbackThemeId]),
                $feedbackThemeId,
            );
            $filters[] = 'Тема обратной связи: '.$label;
        }

        $goalTagId = $this->selectedGoalTagId($state);
        if ($goalTagId !== null) {
            $label = $this->optionLabel(
                app(TrackerGoalTagCatalog::class)->optionsForSelection($organizationId, [$goalTagId]),
                $goalTagId,
            );
            $filters[] = 'Категория задачи: '.$label;
        }

        $sourceKey = $this->selectedSourceKey($state);
        if ($sourceKey !== null) {
            $filters[] = 'Источник клиента: '.$this->optionLabel(
                app(AttributionSourceCatalog::class)->optionsForSelection($organizationId, $sourceKey),
                $sourceKey,
            );
        }

        $purchaseType = $this->selectedPurchaseType($state);
        if ($purchaseType !== null) {
            $filters[] = 'Тип покупки: '.(app(PurchaseGroupCatalog::class)->options(app(OrganizationContext::class)->id())[$purchaseType] ?? 'Выбрано');
        }

        $bookingStatus = $this->selectedBookingStatus($state);
        if ($bookingStatus !== null) {
            $filters[] = 'Статус записи: '.(BookingStatusAnalytics::options()[$bookingStatus] ?? 'Выбрано');
        }

        return $filters;
    }

    /** @param array<string, mixed> $state
     * @return list<string>
     */
    private function cohortFilterLabels(array $state): array
    {
        $filters = $this->cohortFilters($state);
        $organizationId = app(OrganizationContext::class)->id();
        $labels = [];
        if ($filters->clientIds !== []) {
            $labels[] = 'Включить клиентов: '.Client::query()->where('organization_id', $organizationId)->whereIn('id', $filters->clientIds)->orderBy('full_name')->pluck('full_name')->implode(', ');
        }
        if ($filters->excludeClientIds !== []) {
            $labels[] = 'Исключить клиентов: '.Client::query()->where('organization_id', $organizationId)->whereIn('id', $filters->excludeClientIds)->orderBy('full_name')->pluck('full_name')->implode(', ');
        }
        if ($filters->savedGroupIds !== []) {
            $labels[] = 'Сохранённые группы: '.collect(app(AnalyticsClientGroupCatalog::class)->rows())->whereIn('id', $filters->savedGroupIds)->pluck('name')->implode(', ');
        }
        if ($filters->segments !== []) {
            $labels[] = 'Категории: '.collect(ClientSegment::labels())->only($filters->segments)->values()->implode(', ');
        }
        if ($filters->archived !== null) {
            $labels[] = 'Архивированность: '.($filters->archived ? 'Архивные' : 'Активные');
        }
        if ($filters->blacklist !== null) {
            $labels[] = 'Чёрный список: '.($filters->blacklist ? 'В списке' : 'Не в списке');
        }
        if ($filters->problemTagIds !== []) {
            $labels[] = 'Категории проблем ('.($filters->problemTagMode === 'all' ? 'все' : 'хотя бы одна').'): '.implode(', ', array_values(app(MedicalProblemTagCatalog::class)->optionsForSelection($organizationId, $filters->problemTagIds)));
        }
        if ($filters->excludeProblemTagIds !== []) {
            $labels[] = 'Исключить категории проблем: '.implode(', ', array_values(app(MedicalProblemTagCatalog::class)->optionsForSelection($organizationId, $filters->excludeProblemTagIds)));
        }
        if ($filters->feedbackThemeIds !== []) {
            $labels[] = 'Темы обратной связи: '.implode(', ', array_values(app(FeedbackThemeCatalog::class)->optionsForSelection($organizationId, $filters->feedbackThemeIds)));
        }
        if ($filters->goalTagIds !== []) {
            $labels[] = 'Категории целей: '.implode(', ', array_values(app(TrackerGoalTagCatalog::class)->optionsForSelection($organizationId, $filters->goalTagIds)));
        }
        if ($filters->genders !== []) {
            $labels[] = 'Пол: '.implode(', ', array_intersect_key(['MALE' => 'Мужчины', 'FEMALE' => 'Женщины'], array_flip($filters->genders)));
        }
        if ($filters->children !== null) {
            $labels[] = 'Возрастная группа: '.($filters->children ? 'Дети' : 'Взрослые');
        } elseif ($filters->ageMin !== null || $filters->ageMax !== null) {
            $labels[] = 'Возраст: '.($filters->ageMin ?? 0).'–'.($filters->ageMax ?? '∞');
        }
        if ($filters->countries !== []) {
            $labels[] = 'Страны: '.implode(', ', $filters->countries);
        }
        if ($filters->sourceKeys !== []) {
            $sourceLabels = [];
            foreach ($filters->sourceKeys as $sourceKey) {
                $sourceLabels[] = app(AttributionSourceCatalog::class)->optionsForSelection($organizationId, $sourceKey)[$sourceKey] ?? $sourceKey;
            }
            $labels[] = 'Источники: '.implode(', ', $sourceLabels);
        }
        if ($filters->serviceIds !== []) {
            $labels[] = 'Услуги: '.Service::query()->where('organization_id', $organizationId)->whereIn('id', $filters->serviceIds)->orderBy('name')->pluck('name')->implode(', ');
        }
        if ($filters->serviceCategories !== []) {
            $labels[] = 'Группы услуг: '.implode(', ', $filters->serviceCategories);
        }
        if ($filters->specialistIds !== []) {
            $labels[] = 'Специалисты: '.Specialist::query()->where('organization_id', $organizationId)->whereIn('id', $filters->specialistIds)->orderBy('display_name')->pluck('display_name')->implode(', ');
        }
        if ($filters->bookingStatuses !== []) {
            $labels[] = 'Статусы записей: '.implode(', ', array_intersect_key([
                BookingStatus::Requested->value => 'Запрошен', BookingStatus::PendingReview->value => 'На проверке', BookingStatus::Completed->value => 'Выполнен', BookingStatus::Confirmed->value => 'Подтверждён', BookingStatus::Rejected->value => 'Отклонён', BookingStatus::Cancelled->value => 'Отменён', BookingStatus::NoShow->value => 'Неявка',
            ], array_flip($filters->bookingStatuses)));
        }
        if ($filters->bookingEventTypes !== []) {
            $labels[] = 'Статусы истории записей: '.implode(', ', array_intersect_key(BookingStatusAnalytics::options(), array_flip($filters->bookingEventTypes)));
        }
        if ($filters->purchaseTypes !== []) {
            $labels[] = 'Группы покупок: '.implode(', ', array_intersect_key(app(PurchaseGroupCatalog::class)->options($organizationId), array_flip($filters->purchaseTypes)));
        }
        if ($filters->purchaseStatuses !== []) {
            $labels[] = 'Статусы покупок: '.implode(', ', array_intersect_key([
                'paid' => 'Оплачена',
                'pending_payment' => 'Ожидает оплаты',
                'refunded' => 'Возвращена',
            ], array_flip($filters->purchaseStatuses)));
        }
        if ($filters->completedVisitsMin !== null || $filters->completedVisitsMax !== null) {
            $labels[] = 'Завершённых визитов: '.($filters->completedVisitsMin ?? 0).'–'.($filters->completedVisitsMax ?? '∞');
        }
        if ($filters->lastVisitFrom !== null || $filters->lastVisitTo !== null) {
            $labels[] = 'Последний визит: '.($filters->lastVisitFrom ?? '—').' — '.($filters->lastVisitTo ?? '—');
        }
        if ($filters->hasPainScore !== null) {
            $labels[] = 'Источник оценки боли: '.$filters->painScoreBasis->label();
            $labels[] = 'Оценка боли: '.($filters->hasPainScore ? 'Есть оценка' : 'Без оценки');
        } elseif ($filters->painScoreMin !== null || $filters->painScoreMax !== null) {
            $labels[] = 'Источник оценки боли: '.$filters->painScoreBasis->label();
        }
        if ($filters->painScoreMin !== null || $filters->painScoreMax !== null) {
            $labels[] = 'Диапазон боли: '.($filters->painScoreMin ?? 0).'–'.($filters->painScoreMax ?? 10);
        }
        if ($filters->satisfactionMin !== null || $filters->satisfactionMax !== null) {
            $labels[] = 'Диапазон удовлетворённости: '.($filters->satisfactionMin ?? 0).'–'.($filters->satisfactionMax ?? 10);
        }

        return $labels === [] ? ['Все клиенты'] : $labels;
    }

    /** @param array<int|string, string> $options */
    private function optionLabel(array $options, int|string $value): string
    {
        return (string) ($options[$value] ?? $options[(string) $value] ?? 'Выбрано');
    }

    /** @param array<string, mixed> $state */
    private function cohortFilters(array $state): ClientCohortFilters
    {
        $first = static function (mixed $value): mixed {
            if (is_array($value)) {
                return $value[0] ?? null;
            }

            return $value;
        };
        $intList = static function (mixed $value): array {
            $values = is_array($value) ? $value : [$value];

            return array_values(array_unique(array_filter(
                array_map(static fn (mixed $item): int => (int) $item, $values),
                static fn (int $item): bool => $item > 0,
            )));
        };
        $list = static function (mixed $value): array {
            $values = is_array($value) ? $value : [$value];

            return array_values(array_filter(array_map(static fn (mixed $item): string => trim((string) $item, " \t\n\r\0\x0B\"'"), $values)));
        };
        $bool = static function (mixed $value): ?bool {
            if (is_bool($value)) {
                return $value;
            }
            if ($value === '1' || $value === 1) {
                return true;
            }
            if ($value === '0' || $value === 0) {
                return false;
            }

            return null;
        };

        $ageRange = app(ClientAgeRangeCatalog::class)->range(
            app(OrganizationContext::class)->id(),
            is_string($state['cohort_age_range'] ?? null) ? $state['cohort_age_range'] : null,
        );
        if (is_string($state['cohort_age_range'] ?? null) && $state['cohort_age_range'] !== '' && $ageRange === null) {
            throw new \InvalidArgumentException('Выбранный возрастной диапазон больше не активен. Выберите другой диапазон.');
        }
        $ageMin = is_numeric($first($state['cohort_age_min'] ?? null)) ? (int) $first($state['cohort_age_min']) : ($ageRange['min'] ?? null);
        $ageMax = is_numeric($first($state['cohort_age_max'] ?? null)) ? (int) $first($state['cohort_age_max']) : ($ageRange['max'] ?? null);
        $selectedClientIds = $intList($state['client_id'] ?? []);
        $cohortClientIds = $intList($state['cohort_client_ids'] ?? []);
        if ($selectedClientIds !== []) {
            $cohortClientIds = $cohortClientIds === []
                ? $selectedClientIds
                : array_values(array_intersect($cohortClientIds, $selectedClientIds));
        }

        return new ClientCohortFilters(
            clientIds: $cohortClientIds,
            excludeClientIds: $intList($state['cohort_exclude_client_ids'] ?? []),
            savedGroupIds: $intList($state['cohort_saved_group_ids'] ?? []),
            segments: $list($state['cohort_segments'] ?? []),
            archived: $bool($state['cohort_archived'] ?? null),
            blacklist: $bool($state['cohort_blacklist'] ?? null),
            problemTagIds: $intList(($state['cohort_problem_tag_ids'] ?? []) !== [] ? $state['cohort_problem_tag_ids'] : ($state['problem_tag_id'] ?? [])),
            problemTagMode: (string) ($state['cohort_problem_tag_mode'] ?? 'any'),
            excludeProblemTagIds: $intList($state['cohort_exclude_problem_tag_ids'] ?? []),
            feedbackThemeIds: $intList(($state['cohort_feedback_theme_ids'] ?? []) !== [] ? $state['cohort_feedback_theme_ids'] : ($state['feedback_theme_id'] ?? [])),
            goalTagIds: $intList(($state['cohort_goal_tag_ids'] ?? []) !== [] ? $state['cohort_goal_tag_ids'] : ($state['goal_tag_id'] ?? [])),
            ageMin: $ageMin,
            ageMax: $ageMax,
            genders: $list($state['cohort_genders'] ?? []),
            children: $bool($state['cohort_children'] ?? null),
            countries: $list($state['cohort_countries'] ?? []),
            sourceKeys: $list(($state['cohort_source_keys'] ?? []) !== [] ? $state['cohort_source_keys'] : ($state['source_key'] ?? [])),
            serviceIds: $intList($state['cohort_service_ids'] ?? []),
            serviceCategories: $list($state['cohort_service_categories'] ?? []),
            specialistIds: $intList($state['cohort_specialist_ids'] ?? []),
            bookingStatuses: $list($state['cohort_booking_statuses'] ?? []),
            bookingEventTypes: $list($state['cohort_booking_event_types'] ?? []),
            completedVisitsMin: is_numeric($first($state['cohort_completed_visits_min'] ?? null)) ? (int) $first($state['cohort_completed_visits_min']) : null,
            completedVisitsMax: is_numeric($first($state['cohort_completed_visits_max'] ?? null)) ? (int) $first($state['cohort_completed_visits_max']) : null,
            lastVisitFrom: is_string($first($state['cohort_last_visit_from'] ?? null)) ? $first($state['cohort_last_visit_from']) : null,
            lastVisitTo: is_string($first($state['cohort_last_visit_to'] ?? null)) ? $first($state['cohort_last_visit_to']) : null,
            purchaseTypes: $list($state['cohort_purchase_types'] ?? []),
            purchaseStatuses: $list($state['cohort_purchase_statuses'] ?? []),
            painScoreBasis: PainScoreBasis::fromInput($state['cohort_pain_score_basis'] ?? PainScoreBasis::Any->value),
            hasPainScore: $bool($state['cohort_has_pain_score'] ?? null),
            painScoreMin: is_numeric($first($state['cohort_pain_score_min'] ?? null)) ? (int) $first($state['cohort_pain_score_min']) : null,
            painScoreMax: is_numeric($first($state['cohort_pain_score_max'] ?? null)) ? (int) $first($state['cohort_pain_score_max']) : null,
            satisfactionMin: is_numeric($first($state['cohort_satisfaction_min'] ?? null)) ? (int) $first($state['cohort_satisfaction_min']) : null,
            satisfactionMax: is_numeric($first($state['cohort_satisfaction_max'] ?? null)) ? (int) $first($state['cohort_satisfaction_max']) : null,
        );
    }

    /** @param array<string, mixed>|Get $stateOrGet */
    private function previewCountForState(array|Get $stateOrGet, ?DashboardPeriod $period = null): int
    {
        if ($stateOrGet instanceof Get) {
            $state = [];
            foreach (array_keys($this->defaultCohortState()) as $key) {
                $state[$key] = $stateOrGet($key);
            }
            $state['client_id'] = $stateOrGet('client_id');
            $state['problem_tag_id'] = $stateOrGet('problem_tag_id');
            $state['feedback_theme_id'] = $stateOrGet('feedback_theme_id');
            $state['goal_tag_id'] = $stateOrGet('goal_tag_id');
            $state['source_key'] = $stateOrGet('source_key');
            $state['period'] = $stateOrGet('period');
            $state['start_date'] = $stateOrGet('start_date');
            $state['end_date'] = $stateOrGet('end_date');
        } else {
            $state = $stateOrGet;
        }
        if ($period === null) {
            try {
                $period = DashboardPeriod::fromFilters($state, app(OrganizationContext::class)->organization()->defaultTimezone());
            } catch (ValidationException) {
                return 0;
            }
        }
        $actor = Auth::user();
        if (! $actor instanceof User) {
            return 0;
        }

        try {
            return app(ClientCohortQuery::class)->previewCount($actor, $this->cohortFilters($state), $period);
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array<string, mixed> */
    private function defaultCohortState(): array
    {
        return [
            'cohort_client_ids' => [], 'cohort_segments' => [], 'cohort_archived' => null, 'cohort_blacklist' => null,
            'cohort_exclude_client_ids' => [], 'cohort_saved_group_ids' => [],
            'cohort_problem_tag_ids' => [], 'cohort_problem_tag_mode' => 'any', 'cohort_feedback_theme_ids' => [],
            'cohort_exclude_problem_tag_ids' => [], 'cohort_goal_tag_ids' => [], 'cohort_age_min' => null, 'cohort_age_max' => null, 'cohort_age_range' => null, 'cohort_genders' => [],
            'cohort_children' => null, 'cohort_countries' => [], 'cohort_source_keys' => [], 'cohort_service_ids' => [], 'cohort_service_categories' => [],
            'cohort_specialist_ids' => [], 'cohort_booking_statuses' => [], 'cohort_booking_event_types' => [], 'cohort_completed_visits_min' => null,
            'cohort_completed_visits_max' => null, 'cohort_last_visit_from' => null, 'cohort_last_visit_to' => null,
            'cohort_purchase_types' => [], 'cohort_purchase_statuses' => [], 'cohort_has_pain_score' => null,
            'cohort_pain_score_basis' => PainScoreBasis::Any->value, 'cohort_pain_score_min' => null, 'cohort_pain_score_max' => null, 'cohort_satisfaction_min' => null,
            'cohort_satisfaction_max' => null,
        ];
    }

    /** @return array<string, string> */
    private function countryOptions(): array
    {
        return Client::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->distinct()
            ->orderBy('country')
            ->pluck('country', 'country')
            ->all();
    }

    /** @param array<string, mixed> $state
     * @return list<array<string, mixed>>
     */
    private function cohortRows(User $actor, array $state, DashboardPeriod $period): array
    {
        return array_map(static function (array $row): array {
            unset($row['id'], $row['url']);

            return $row;
        }, $this->cohortDisplayRows($actor, $state, $period));
    }

    /** @param array<string, mixed> $state
     * @return list<array<string, mixed>>
     */
    private function cohortDisplayRows(User $actor, array $state, DashboardPeriod $period): array
    {
        $sourceOptions = app(AttributionSourceCatalog::class)->optionsForSelection(app(OrganizationContext::class)->id());
        $reason = $this->cohortReason($state);

        $rows = app(ClientCohortQuery::class)->for($actor, $this->cohortFilters($state), $period)
            ->orderBy('clients.full_name')
            ->get(['clients.id', 'clients.full_name', 'clients.gender', 'clients.birth_date', 'clients.country', 'clients.lead_source'])
            ->map(static fn (Client $client): array => [
                'id' => (int) $client->getKey(),
                'name' => (string) $client->full_name,
                'gender' => match ($client->gender) {
                    'MALE' => 'Мужчина',
                    'FEMALE' => 'Женщина',
                    default => null,
                },
                'birth_date' => $client->birth_date?->toDateString(),
                'country' => $client->country,
                'source' => $sourceOptions[(string) $client->lead_source] ?? ($client->lead_source ?: null),
                'reason' => $reason,
                'url' => ClientResource::getUrl('view', ['record' => $client]),
            ])
            ->values()
            ->all();

        return array_values($rows);
    }

    /** @param array<string, mixed> $state */
    private function cohortReason(array $state): string
    {
        $filters = $this->cohortFilters($state);
        $parts = [];
        if ($filters->clientIds !== []) {
            $parts[] = 'конкретные клиенты';
        }
        if ($filters->excludeClientIds !== []) {
            $parts[] = 'исключение клиентов';
        }
        if ($filters->savedGroupIds !== []) {
            $parts[] = 'сохранённые группы';
        }
        if ($filters->segments !== []) {
            $parts[] = 'категории клиентов';
        }
        if ($filters->archived !== null) {
            $parts[] = 'архивный статус';
        }
        if ($filters->blacklist !== null) {
            $parts[] = 'чёрный список';
        }
        if ($filters->problemTagIds !== [] || $filters->excludeProblemTagIds !== []) {
            $parts[] = 'категории проблем';
        }
        if ($filters->feedbackThemeIds !== []) {
            $parts[] = 'темы обратной связи';
        }
        if ($filters->goalTagIds !== []) {
            $parts[] = 'категории целей';
        }
        if ($filters->ageMin !== null || $filters->ageMax !== null || $filters->children !== null) {
            $parts[] = 'возраст';
        }
        if ($filters->genders !== []) {
            $parts[] = 'пол';
        }
        if ($filters->countries !== []) {
            $parts[] = 'страны';
        }
        if ($filters->sourceKeys !== []) {
            $parts[] = 'источники привлечения';
        }
        if ($filters->serviceIds !== [] || $filters->serviceCategories !== [] || $filters->specialistIds !== [] || $filters->bookingStatuses !== [] || $filters->bookingEventTypes !== []) {
            $parts[] = 'записи и услуги';
        }
        if ($filters->completedVisitsMin !== null || $filters->completedVisitsMax !== null || $filters->lastVisitFrom !== null || $filters->lastVisitTo !== null) {
            $parts[] = 'история визитов';
        }
        if ($filters->purchaseTypes !== [] || $filters->purchaseStatuses !== []) {
            $parts[] = 'покупки';
        }
        if ($filters->hasPainScore !== null || $filters->painScoreMin !== null || $filters->painScoreMax !== null) {
            $parts[] = 'оценка боли: '.$filters->painScoreBasis->label();
        }
        if ($filters->satisfactionMin !== null || $filters->satisfactionMax !== null) {
            $parts[] = 'удовлетворённость';
        }

        return $parts === [] ? 'Все клиенты' : 'Соответствует фильтрам: '.implode(', ', $parts);
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function stateForAggregate(array $state, string $dimension, string|int $value): array
    {
        $append = static function (array $state, string $key, string|int $value): array {
            $values = is_array($state[$key] ?? null) ? $state[$key] : [];
            $values[] = $value;
            $state[$key] = array_values(array_unique($values));

            return $state;
        };
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return match ($dimension) {
            'pain' => $id === false ? throw new \InvalidArgumentException('Категория проблемы недоступна для детализации.') : $this->aggregateProblemState($state, (int) $id, $append),
            'feedback' => $id === false ? throw new \InvalidArgumentException('Тема обратной связи недоступна для детализации.') : $append($state, 'cohort_feedback_theme_ids', (int) $id),
            'goals' => $id === false ? throw new \InvalidArgumentException('Категория цели недоступна для детализации.') : $append($state, 'cohort_goal_tag_ids', (int) $id),
            'services' => $id === false ? throw new \InvalidArgumentException('Услуга недоступна для детализации.') : $append($state, 'cohort_service_ids', (int) $id),
            'specialists' => $id === false ? throw new \InvalidArgumentException('Специалист недоступен для детализации.') : $append($state, 'cohort_specialist_ids', (int) $id),
            'purchases' => $append($state, 'cohort_purchase_types', (string) $value),
            'booking_statuses' => $append($state, 'cohort_booking_event_types', (string) $value),
            'segments' => $append($state, 'cohort_segments', (string) $value),
            'acquisition' => $this->aggregateSourceState($state, (string) $value, $append),
            'demographics_age' => $this->aggregateAgeState($state, (string) $value),
            'demographics_sex' => $this->aggregateSexState($state, (string) $value, $append),
            'demographics_children' => match ((string) $value) {
                'Дети' => [...$state, 'cohort_children' => true, 'cohort_age_min' => null, 'cohort_age_max' => null, 'cohort_age_range' => null],
                'Взрослые' => [...$state, 'cohort_children' => false, 'cohort_age_min' => null, 'cohort_age_max' => null, 'cohort_age_range' => null],
                default => throw new \InvalidArgumentException('Категория возраста недоступна для детализации.'),
            },
            'demographics_country' => $this->aggregateCountryState($state, (string) $value, $append),
            default => throw new \InvalidArgumentException('Для этого агрегата детализация клиентов недоступна.'),
        };
    }

    /** @param array<string, mixed> $state
     * @param  callable(array<string, mixed>, string, string|int): array<string, mixed>  $append
     * @return array<string, mixed>
     */
    private function aggregateProblemState(array $state, int $tagId, callable $append): array
    {
        $state = $append($state, 'cohort_problem_tag_ids', $tagId);
        $state['cohort_problem_tag_mode'] = 'all';
        $state['cohort_exclude_problem_tag_ids'] = array_values(array_diff((array) ($state['cohort_exclude_problem_tag_ids'] ?? []), [$tagId]));

        return $state;
    }

    /** @param array<string, mixed> $state
     * @param  callable(array<string, mixed>, string, string|int): array<string, mixed>  $append
     * @return array<string, mixed>
     */
    private function aggregateSourceState(array $state, string $label, callable $append): array
    {
        foreach (app(AttributionSourceCatalog::class)->rows(app(OrganizationContext::class)->id()) as $mapping) {
            if ($mapping['label'] === $label) {
                return $append($state, 'cohort_source_keys', $mapping['source_key']);
            }
        }

        throw new \InvalidArgumentException('Источник привлечения недоступен для детализации.');
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function aggregateAgeState(array $state, string $label): array
    {
        foreach (app(ClientAgeRangeCatalog::class)->rows(app(OrganizationContext::class)->id()) as $range) {
            if ($range['label'] === $label) {
                return [
                    ...$state,
                    'cohort_age_min' => $range['min'],
                    'cohort_age_max' => $range['max'],
                    'cohort_age_range' => null,
                    'cohort_children' => null,
                ];
            }
        }

        throw new \InvalidArgumentException('Возрастной диапазон недоступен для детализации.');
    }

    /** @param array<string, mixed> $state
     * @param  callable(array<string, mixed>, string, string|int): array<string, mixed>  $append
     * @return array<string, mixed>
     */
    private function aggregateSexState(array $state, string $label, callable $append): array
    {
        return match ($label) {
            'Мужчины' => $append($state, 'cohort_genders', 'MALE'),
            'Женщины' => $append($state, 'cohort_genders', 'FEMALE'),
            default => throw new \InvalidArgumentException('Пол недоступен для детализации.'),
        };
    }

    /** @param array<string, mixed> $state
     * @param  callable(array<string, mixed>, string, string|int): array<string, mixed>  $append
     * @return array<string, mixed>
     */
    private function aggregateCountryState(array $state, string $label, callable $append): array
    {
        if ($label === 'Не указана') {
            throw new \InvalidArgumentException('Клиенты без страны не поддерживают точную детализацию.');
        }

        return $append($state, 'cohort_countries', $label);
    }

    /** @return array<int|string, string> */
    private function clientSearchResults(string $search): array
    {
        $actor = Auth::user();
        if (! $actor instanceof User) {
            return [];
        }

        $searcher = app(ClientSearch::class);
        $query = Client::query()->where('organization_id', app(OrganizationContext::class)->id());

        return $searcher->formatOptionLabels(
            $searcher->apply($query, $search)
                ->orderBy('full_name')
                ->orderBy('id')
                ->limit(ClientSearch::MAX_RESULTS)
                ->get(['id', 'full_name', 'email', 'phone']),
        );
    }

    private function clientOptionLabel(mixed $value): ?string
    {
        $actor = Auth::user();
        $clientId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! $actor instanceof User || $clientId === false) {
            return null;
        }

        $client = Client::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->find($clientId);

        return $client instanceof Client ? app(ClientSearch::class)->formatOptionLabel($client) : null;
    }

    /** @param array<string, mixed> $state */
    private function selectedClient(array $state): ?Client
    {
        $rawClientId = $state['client_id'] ?? null;
        if ($rawClientId === null || $rawClientId === '') {
            return null;
        }

        $clientId = filter_var($rawClientId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($clientId === false) {
            return null;
        }

        return Client::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->findOrFail($clientId);
    }

    /** @param array<string, mixed> $state */
    private function selectedProblemTagId(array $state): ?int
    {
        $value = filter_var($state['problem_tag_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $value === false ? null : (int) $value;
    }

    /** @param array<string, mixed> $state */
    private function selectedFeedbackThemeId(array $state): ?int
    {
        $value = filter_var($state['feedback_theme_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $value === false ? null : (int) $value;
    }

    private function stateSessionKey(): string
    {
        return 'analytics.console.state.'.app(OrganizationContext::class)->id();
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function persistedState(array $state): array
    {
        $period = $state['period'] ?? DashboardPeriod::DefaultPreset;
        if ($period instanceof \BackedEnum) {
            $period = $period->value;
        }

        return [
            'dimensions' => array_values(array_map('strval', (array) ($state['dimensions'] ?? []))),
            ...array_intersect_key($state, $this->defaultCohortState()),
            'client_id' => $state['client_id'] ?? null,
            'problem_tag_id' => $state['problem_tag_id'] ?? null,
            'feedback_theme_id' => $state['feedback_theme_id'] ?? null,
            'goal_tag_id' => $state['goal_tag_id'] ?? null,
            'source_key' => $state['source_key'] ?? null,
            'purchase_type' => $state['purchase_type'] ?? null,
            'booking_status' => $state['booking_status'] ?? null,
            'period' => $period,
            'start_date' => $state['start_date'] ?? null,
            'end_date' => $state['end_date'] ?? null,
        ];
    }

    /** @param array<string, mixed> $state */
    private function selectedGoalTagId(array $state): ?int
    {
        $value = filter_var($state['goal_tag_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $value === false ? null : (int) $value;
    }

    /** @param array<string, mixed> $state */
    private function selectedSourceKey(array $state): ?string
    {
        $value = $state['source_key'] ?? null;
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^[a-z][a-z0-9_]{1,119}$/D', $value) === 1 ? $value : null;
    }

    /** @param array<string, mixed> $state */
    private function selectedPurchaseType(array $state): ?string
    {
        $value = $state['purchase_type'] ?? null;

        return is_string($value) && array_key_exists($value, app(PurchaseGroupCatalog::class)->options(app(OrganizationContext::class)->id())) ? $value : null;
    }

    /** @param array<string, mixed> $state */
    private function selectedBookingStatus(array $state): ?string
    {
        $value = $state['booking_status'] ?? null;

        return is_string($value) && array_key_exists($value, BookingStatusAnalytics::options()) ? $value : null;
    }

    /** @param array<string, mixed> $state
     * @return array<string, string>
     */
    private function cohortRuleVersions(array $state): array
    {
        $organizationId = app(OrganizationContext::class)->id();
        $filters = $this->cohortFilters($state);
        $groupVersions = collect(app(AnalyticsClientGroupCatalog::class)->rows())
            ->whereIn('id', $filters->savedGroupIds)
            ->mapWithKeys(static fn (array $group): array => [
                'saved_group_'.$group['id'] => 'group-'.$group['id'].'-v'.$group['version'],
            ])
            ->all();

        return [
            'client_cohort' => 'client-cohort-v1',
            ...$groupVersions,
            'age_ranges' => $this->settingVersion(OrganizationSettingKey::AnalyticsAgeRanges, $organizationId, 'age-ranges-default-v1'),
            'purchase_groups' => $this->settingVersion(OrganizationSettingKey::AnalyticsPurchaseGroups, $organizationId, 'purchase-groups-default-v1'),
            'segment_rules' => $this->settingVersion(OrganizationSettingKey::ClientSegmentSleepingDays, $organizationId, 'segment-rules-default-v1'),
        ];
    }

    /** @param array<string, mixed> $state
     * @return array<string, string>
     */
    private function metricRuleVersions(array $state): array
    {
        if (! in_array('custom_metrics', (array) ($state['dimensions'] ?? []), true)) {
            return [];
        }

        return app(AnalyticsMetricConfiguration::class)->metrics(true)
            ->mapWithKeys(static fn ($metric): array => [
                'metric_'.$metric->stable_key => 'metric-'.$metric->stable_key.'-v'.$metric->version,
            ])
            ->all();
    }

    private function settingVersion(OrganizationSettingKey $key, int $organizationId, string $fallback): string
    {
        $setting = OrganizationSetting::query()
            ->where('organization_id', $organizationId)
            ->where('setting_key', $key->value)
            ->first();

        if ($setting?->updated_at === null) {
            return $fallback;
        }

        return $setting->updated_at->format('YmdHis').'@'.$key->value;
    }

    /** @return array<int, string> */
    private static function scoreOptions(): array
    {
        $options = [];
        for ($score = 0; $score <= 10; $score++) {
            $options[$score] = (string) $score;
        }

        return $options;
    }
}
