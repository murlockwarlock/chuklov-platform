<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\Analytics\Application\AcquisitionAnalytics;
use App\Modules\Analytics\Application\AnalyticsMetricConfiguration;
use App\Modules\Analytics\Application\AnalyticsMetricEvaluator;
use App\Modules\Analytics\Application\AnalyticsReportExport;
use App\Modules\Analytics\Application\BookingStatusAnalytics;
use App\Modules\Analytics\Application\ClientSegmentAnalytics;
use App\Modules\Analytics\Application\Data\AnalyticsMetricFilters;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\DemographicsAnalytics;
use App\Modules\Analytics\Application\FeedbackAnalytics;
use App\Modules\Analytics\Application\FinanceAnalytics;
use App\Modules\Analytics\Application\GoalAnalytics;
use App\Modules\Analytics\Application\PainAnalytics;
use App\Modules\Analytics\Application\PurchaseAnalytics;
use App\Modules\Analytics\Application\SchedulingAnalytics;
use App\Modules\Analytics\Application\ServiceVisitAnalytics;
use App\Modules\Attribution\Application\AttributionSourceCatalog;
use App\Modules\Feedback\Application\FeedbackThemeCatalog;
use App\Modules\Finance\Application\FinancialReconciliationContract;
use App\Modules\Identity\Application\ClientSearch;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\MedicalProblemTagCatalog;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Tracker\Application\TrackerGoalTagCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
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
        $this->form->fill([
            'dimensions' => ['pain', 'custom_metrics'],
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
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Параметры анализа'))
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
                            ->options(PurchaseAnalytics::options())
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
                            ->visible(fn (Get $get): bool => $get('period') === DashboardPeriod::Custom),
                        DatePicker::make('end_date')
                            ->label(__('Конец'))
                            ->format('Y-m-d')
                            ->displayFormat('d.m.Y')
                            ->requiredIf('period', DashboardPeriod::Custom)
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
                ->action(fn (): StreamedResponse => $this->export()),
        ];
    }

    public function analyze(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $this->dimensionErrors = [];
        $state = $this->form->getState();
        $dimensions = is_array($state['dimensions'] ?? null) ? array_values($state['dimensions']) : [];
        $client = $this->selectedClient($state);
        $problemTagId = $this->selectedProblemTagId($state);
        $feedbackThemeId = $this->selectedFeedbackThemeId($state);
        $goalTagId = $this->selectedGoalTagId($state);
        $sourceKey = $this->selectedSourceKey($state);
        $purchaseType = $this->selectedPurchaseType($state);
        $bookingStatus = $this->selectedBookingStatus($state);
        $organization = app(OrganizationContext::class)->organization();
        $period = DashboardPeriod::fromFilters($state, $organization->defaultTimezone());
        $results = [];

        foreach ($dimensions as $dimension) {
            try {
                $results[(string) $dimension] = match ((string) $dimension) {
                    'pain' => app(PainAnalytics::class)->handle($actor, $period, $client, $problemTagId)->toArray(),
                    'acquisition' => $this->acquisition($actor, $period, $client, $sourceKey),
                    'scheduling' => $this->scheduling($actor, $period, $client),
                    'finance' => $this->finance($actor, $period, $client),
                    'feedback' => $this->feedback($actor, $period, $client, $feedbackThemeId),
                    'goals' => $this->goals($actor, $period, $client, $goalTagId),
                    'demographics' => $this->demographics($actor, $period, $client),
                    'services' => $this->services($actor, $period, $client),
                    'purchases' => $this->purchases($actor, $period, $client, $purchaseType),
                    'booking_statuses' => $this->bookingStatuses($actor, $period, $client, $bookingStatus),
                    'segments' => app(ClientSegmentAnalytics::class)->handle($actor, $client),
                    'custom_metrics' => $this->customMetrics($actor, $period, $client, $state),
                    default => null,
                };
            } catch (Throwable) {
                $this->dimensionErrors[(string) $dimension] = $this->dimensionLabel((string) $dimension);
            }
        }

        $this->results = array_filter($results, static fn (mixed $result): bool => is_array($result));
        Notification::make()->title(__('Анализ обновлён'))->success()->send();
    }

    public function export(): StreamedResponse
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $state = $this->form->getState();
        $organization = app(OrganizationContext::class)->organization();
        $period = DashboardPeriod::fromFilters($state, $organization->defaultTimezone());
        $client = $this->selectedClient($state);
        $problemTagId = $this->selectedProblemTagId($state);
        $feedbackThemeId = $this->selectedFeedbackThemeId($state);
        $goalTagId = $this->selectedGoalTagId($state);
        $sourceKey = $this->selectedSourceKey($state);
        $purchaseType = $this->selectedPurchaseType($state);
        $bookingStatus = $this->selectedBookingStatus($state);
        $results = [];
        foreach ((array) ($state['dimensions'] ?? []) as $dimension) {
            try {
                $results[(string) $dimension] = match ((string) $dimension) {
                    'pain' => app(PainAnalytics::class)->handle($actor, $period, $client, $problemTagId)->toArray(),
                    'acquisition' => $this->acquisition($actor, $period, $client, $sourceKey),
                    'scheduling' => $this->scheduling($actor, $period, $client),
                    'finance' => $this->finance($actor, $period, $client),
                    'feedback' => $this->feedback($actor, $period, $client, $feedbackThemeId),
                    'goals' => $this->goals($actor, $period, $client, $goalTagId),
                    'demographics' => $this->demographics($actor, $period, $client),
                    'services' => $this->services($actor, $period, $client),
                    'purchases' => $this->purchases($actor, $period, $client, $purchaseType),
                    'booking_statuses' => $this->bookingStatuses($actor, $period, $client, $bookingStatus),
                    'segments' => app(ClientSegmentAnalytics::class)->handle($actor, $client),
                    'custom_metrics' => $this->customMetrics($actor, $period, $client, $state),
                    default => null,
                };
            } catch (Throwable) {
                $results[(string) $dimension] = ['available' => false];
            }
        }

        $exportResults = array_filter($results, static fn (mixed $result): bool => is_array($result));
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
                'timezone' => $period->timezone,
                'currency' => $currency,
            ],
        );
    }

    /** @return array<string, mixed> */
    private function acquisition(User $actor, DashboardPeriod $period, ?Client $client = null, ?string $sourceKey = null): array
    {
        $data = app(AcquisitionAnalytics::class)->handle($actor, $period, $client, $sourceKey);

        return [
            'new_clients' => $data->newClients,
            'sources' => array_map(static fn ($source): array => [
                'label' => $source->label,
                'count' => $source->count,
            ], $data->sources),
        ];
    }

    /** @return array<string, mixed> */
    private function scheduling(User $actor, DashboardPeriod $period, ?Client $client = null): array
    {
        $data = app(SchedulingAnalytics::class)->handle($actor, $period, $client);

        return [
            'bookings' => $data->bookings,
            'visits' => $data->visits,
            'cancellations' => $data->cancellations,
            'reschedules' => $data->reschedules,
            'no_shows' => $data->noShows,
        ];
    }

    /** @return array<string, mixed> */
    private function finance(User $actor, DashboardPeriod $period, ?Client $client = null): array
    {
        $data = app(FinanceAnalytics::class)->handle($actor, $period, $client);
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
    private function feedback(User $actor, DashboardPeriod $period, ?Client $client = null, ?int $themeId = null): array
    {
        return app(FeedbackAnalytics::class)->handle($actor, $period, $client, $themeId)->toArray();
    }

    /** @return array<string, mixed> */
    private function goals(User $actor, DashboardPeriod $period, ?Client $client = null, ?int $goalTagId = null): array
    {
        return app(GoalAnalytics::class)->handle($actor, $period, $client, $goalTagId)->toArray();
    }

    /** @return array<string, mixed> */
    private function demographics(User $actor, DashboardPeriod $period, ?Client $client = null): array
    {
        return app(DemographicsAnalytics::class)->handle($actor, $period, $client)->toArray();
    }

    /** @return array<string, mixed> */
    private function services(User $actor, DashboardPeriod $period, ?Client $client = null): array
    {
        return app(ServiceVisitAnalytics::class)->handle($actor, $period, $client)->toArray();
    }

    /** @return array<string, mixed> */
    private function purchases(User $actor, DashboardPeriod $period, ?Client $client = null, ?string $catalogType = null): array
    {
        return app(PurchaseAnalytics::class)->handle($actor, $period, $client, $catalogType)->toArray();
    }

    /** @return array<string, mixed> */
    private function bookingStatuses(User $actor, DashboardPeriod $period, ?Client $client = null, ?string $status = null): array
    {
        return app(BookingStatusAnalytics::class)->handle($actor, $period, $client, $status)->toArray();
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{metrics: list<array<string, mixed>>}
     */
    private function customMetrics(User $actor, DashboardPeriod $period, ?Client $client, array $state): array
    {
        $metrics = app(AnalyticsMetricConfiguration::class)->metrics(true);
        $filters = new AnalyticsMetricFilters(
            problemTagId: $this->selectedProblemTagId($state),
            feedbackThemeId: $this->selectedFeedbackThemeId($state),
            goalTagId: $this->selectedGoalTagId($state),
            sourceKey: $this->selectedSourceKey($state),
            purchaseType: $this->selectedPurchaseType($state),
            bookingStatus: $this->selectedBookingStatus($state),
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
            $filters[] = 'Тип покупки: '.(PurchaseAnalytics::options()[$purchaseType] ?? 'Выбрано');
        }

        $bookingStatus = $this->selectedBookingStatus($state);
        if ($bookingStatus !== null) {
            $filters[] = 'Статус записи: '.(BookingStatusAnalytics::options()[$bookingStatus] ?? 'Выбрано');
        }

        return $filters;
    }

    /** @param array<int|string, string> $options */
    private function optionLabel(array $options, int|string $value): string
    {
        return (string) ($options[$value] ?? $options[(string) $value] ?? 'Выбрано');
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

        return is_string($value) && array_key_exists($value, PurchaseAnalytics::options()) ? $value : null;
    }

    /** @param array<string, mixed> $state */
    private function selectedBookingStatus(array $state): ?string
    {
        $value = $state['booking_status'] ?? null;

        return is_string($value) && array_key_exists($value, BookingStatusAnalytics::options()) ? $value : null;
    }
}
