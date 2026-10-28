<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\Analytics\Application\AcquisitionAnalytics;
use App\Modules\Analytics\Application\AnalyticsReportExport;
use App\Modules\Analytics\Application\BookingStatusAnalytics;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\DemographicsAnalytics;
use App\Modules\Analytics\Application\FeedbackAnalytics;
use App\Modules\Analytics\Application\FinanceAnalytics;
use App\Modules\Analytics\Application\GoalAnalytics;
use App\Modules\Analytics\Application\PainAnalytics;
use App\Modules\Analytics\Application\PurchaseAnalytics;
use App\Modules\Analytics\Application\SchedulingAnalytics;
use App\Modules\Analytics\Application\ServiceVisitAnalytics;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Filament\Actions\Action;
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
            'dimensions' => ['pain'],
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
                        Select::make('dimensions')
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
                            ])
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->required()
                            ->default(['pain']),
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
        $state = $this->form->getState();
        $dimensions = is_array($state['dimensions'] ?? null) ? array_values($state['dimensions']) : [];
        $organization = app(OrganizationContext::class)->organization();
        $period = DashboardPeriod::fromFilters($state, $organization->defaultTimezone());
        $results = [];

        foreach ($dimensions as $dimension) {
            try {
                $results[(string) $dimension] = match ((string) $dimension) {
                    'pain' => app(PainAnalytics::class)->handle($actor, $period)->toArray(),
                    'acquisition' => $this->acquisition($actor, $period),
                    'scheduling' => $this->scheduling($actor, $period),
                    'finance' => $this->finance($actor, $period),
                    'feedback' => $this->feedback($actor, $period),
                    'goals' => $this->goals($actor, $period),
                    'demographics' => $this->demographics($actor, $period),
                    'services' => $this->services($actor, $period),
                    'purchases' => $this->purchases($actor, $period),
                    'booking_statuses' => $this->bookingStatuses($actor, $period),
                    default => null,
                };
            } catch (Throwable) {
                $results[(string) $dimension] = ['available' => false];
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
        $results = [];
        foreach ((array) ($state['dimensions'] ?? []) as $dimension) {
            try {
                $results[(string) $dimension] = match ((string) $dimension) {
                    'pain' => app(PainAnalytics::class)->handle($actor, $period)->toArray(),
                    'acquisition' => $this->acquisition($actor, $period),
                    'scheduling' => $this->scheduling($actor, $period),
                    'finance' => $this->finance($actor, $period),
                    'feedback' => $this->feedback($actor, $period),
                    'goals' => $this->goals($actor, $period),
                    'demographics' => $this->demographics($actor, $period),
                    'services' => $this->services($actor, $period),
                    'purchases' => $this->purchases($actor, $period),
                    'booking_statuses' => $this->bookingStatuses($actor, $period),
                    default => null,
                };
            } catch (Throwable) {
                $results[(string) $dimension] = ['available' => false];
            }
        }

        return app(AnalyticsReportExport::class)->download($actor, array_filter($results, static fn (mixed $result): bool => is_array($result)));
    }

    private function acquisition(User $actor, DashboardPeriod $period): array
    {
        $data = app(AcquisitionAnalytics::class)->handle($actor, $period);

        return [
            'new_clients' => $data->newClients,
            'sources' => array_map(static fn ($source): array => [
                'label' => $source->label,
                'count' => $source->count,
            ], $data->sources),
        ];
    }

    private function scheduling(User $actor, DashboardPeriod $period): array
    {
        $data = app(SchedulingAnalytics::class)->handle($actor, $period);

        return [
            'bookings' => $data->bookings,
            'visits' => $data->visits,
            'cancellations' => $data->cancellations,
            'reschedules' => $data->reschedules,
            'no_shows' => $data->noShows,
        ];
    }

    private function finance(User $actor, DashboardPeriod $period): array
    {
        $data = app(FinanceAnalytics::class)->handle($actor, $period);

        return [
            'available' => $data->available,
            'currency' => $data->baseCurrency,
            'revenue_minor' => $data->revenueMinor,
            'receipt_count' => $data->receiptCount,
            'debt_minor' => $data->debtMinor,
            'realized_ltv_minor' => $data->realizedLtvMinor,
        ];
    }

    private function feedback(User $actor, DashboardPeriod $period): array
    {
        return app(FeedbackAnalytics::class)->handle($actor, $period)->toArray();
    }

    private function goals(User $actor, DashboardPeriod $period): array
    {
        return app(GoalAnalytics::class)->handle($actor, $period)->toArray();
    }

    private function demographics(User $actor, DashboardPeriod $period): array
    {
        return app(DemographicsAnalytics::class)->handle($actor, $period)->toArray();
    }

    private function services(User $actor, DashboardPeriod $period): array
    {
        return app(ServiceVisitAnalytics::class)->handle($actor, $period)->toArray();
    }

    private function purchases(User $actor, DashboardPeriod $period): array
    {
        return app(PurchaseAnalytics::class)->handle($actor, $period)->toArray();
    }

    private function bookingStatuses(User $actor, DashboardPeriod $period): array
    {
        return app(BookingStatusAnalytics::class)->handle($actor, $period)->toArray();
    }
}
