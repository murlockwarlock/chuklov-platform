<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Modules\AI\Application\Actions\UpdateAiSafetyControl;
use App\Modules\AI\Domain\Models\AiOrganizationDailyBudget;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Registry\AiCapabilityRegistry;
use App\Modules\AI\Domain\Registry\AiProviderCatalog;
use App\Modules\AI\Domain\ValueObjects\AiMoney;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/** @property-read Schema $form */
final class AiMonitoringOverview extends Page
{
    public const int PROVIDER_OVERVIEW_LIMIT = 50;

    protected static ?string $title = 'AI и лимиты';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?string $navigationLabel = 'AI и лимиты';

    protected static string|\UnitEnum|null $navigationGroup = 'Искусственный интеллект';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.ai-monitoring-overview';

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    public static function canAccess(): bool
    {
        $user = Auth::user();
        if ($user === null) {
            return false;
        }

        $context = app(OrganizationContext::class);
        $authorizer = app(OrganizationAuthorizer::class);

        return $authorizer->allows($user, $context->organization(), OrganizationPermission::ViewAiRuns);
    }

    public function getHeading(): string
    {
        return 'AI и лимиты';
    }

    public function getSubheading(): string
    {
        return 'Управляйте доступом AI, дневным бюджетом и подключёнными сервисами организации.';
    }

    public static function canManage(): bool
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return false;
        }

        $context = app(OrganizationContext::class);

        return app(OrganizationAuthorizer::class)->allows(
            $user,
            $context->organization(),
            OrganizationPermission::ManageAiProviders,
        );
    }

    public function mount(): void
    {
        $this->fillSafetyForm();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('AI и лимиты')
                    ->description('Дневной бюджет ограничивает расходы AI за сутки. При достижении лимита новые платные AI-запросы будут остановлены.')
                    ->schema([
                        Toggle::make('is_ai_globally_enabled')
                            ->label('AI включён')
                            ->helperText('Выключите, чтобы временно остановить новые платные AI-запросы.')
                            ->disabled(fn (): bool => ! self::canManage()),
                        TextInput::make('max_daily_spend')
                            ->label('Дневной бюджет')
                            ->prefix('$')
                            ->suffix('/ день')
                            ->helperText('Максимальная сумма расходов AI за один день.')
                            ->inputMode('decimal')
                            ->regex('/^(0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/')
                            ->required()
                            ->disabled(fn (): bool => ! self::canManage()),
                        TextInput::make('max_tokens_per_run')
                            ->label('Максимальная длина одного AI-запуска')
                            ->helperText('Верхний предел длины ответа AI в одном запуске.')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(8192)
                            ->required()
                            ->disabled(fn (): bool => ! self::canManage()),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Дополнительные ограничения')
                    ->description('Дополнительные ограничения для особых сценариев. Обычно менять их не требуется.')
                    ->collapsed()
                    ->schema([
                        TextInput::make('max_runs_per_minute')
                            ->label('Максимум запусков за минуту')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(60)
                            ->required()
                            ->disabled(fn (): bool => ! self::canManage()),
                        TextInput::make('max_tool_calls_per_run')
                            ->label('Максимум действий AI за запуск')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(5)
                            ->required()
                            ->disabled(fn (): bool => ! self::canManage()),
                        TextInput::make('default_timeout_seconds')
                            ->label('Время ожидания ответа по умолчанию, секунд')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(120)
                            ->required()
                            ->disabled(fn (): bool => ! self::canManage()),
                        TextInput::make('max_failover_attempts')
                            ->label('Максимум резервных попыток')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(3)
                            ->required()
                            ->disabled(fn (): bool => ! self::canManage()),
                        Select::make('disabled_capabilities')
                            ->label('Отключённые сценарии AI')
                            ->options(self::capabilityOptions())
                            ->multiple()
                            ->searchable()
                            ->disabled(fn (): bool => ! self::canManage()),
                        Select::make('disabled_providers')
                            ->label('Отключённые сервисы AI')
                            ->options(AiProviderCatalog::options())
                            ->multiple()
                            ->searchable()
                            ->disabled(fn (): bool => ! self::canManage()),
                        Select::make('disabled_tools')
                            ->label('Отключённые действия AI')
                            ->options(self::toolOptions())
                            ->multiple()
                            ->searchable()
                            ->disabled(fn (): bool => ! self::canManage()),
                    ])
                    ->columns(2)
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
        return Form::make([
            EmbeddedSchema::make('form'),
        ])
            ->id('ai-safety-form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')
                        ->label('Сохранить ограничения')
                        ->submit('save')
                        ->visible(fn (): bool => self::canManage()),
                ]),
            ]);
    }

    public function save(): void
    {
        $actor = Auth::user();
        if (! $actor instanceof User || ! self::canManage()) {
            Notification::make()->title('Недостаточно прав для изменения настроек AI')->danger()->send();

            return;
        }

        app(UpdateAiSafetyControl::class)->handle($actor, $this->form->getState());
        $this->fillSafetyForm();
        Notification::make()->title('Ограничения AI сохранены')->success()->send();
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $orgId = app(OrganizationContext::class)->id();
        $today = Carbon::now()->toDateString();

        $safety = AiOrganizationSafetyControl::query()
            ->where('organization_id', $orgId)
            ->first();

        $budget = AiOrganizationDailyBudget::query()
            ->where('organization_id', $orgId)
            ->where('usage_date', $today)
            ->first();

        $runsCountToday = AiRun::query()
            ->where('organization_id', $orgId)
            ->whereDate('created_at', $today)
            ->count();

        $failedRunsCountToday = AiRun::query()
            ->where('organization_id', $orgId)
            ->whereDate('created_at', $today)
            ->whereIn('status', ['failed', 'timed_out', 'invalid_output'])
            ->count();

        $maxDailySpendMinor = $safety !== null ? (int) $safety->max_daily_spend_minor_units : 5000;
        $spentTodayMinor = $budget !== null ? (int) $budget->spent_minor_units : 0;
        $reservedTodayMinor = $budget !== null ? (int) $budget->reserved_minor_units : 0;

        $providers = AiProviderConfiguration::query()
            ->where('organization_id', $orgId)
            ->withCount('models')
            ->orderBy('id')
            ->limit(self::PROVIDER_OVERVIEW_LIMIT)
            ->get();

        return [
            'isAiEnabled' => $safety !== null ? $safety->is_ai_globally_enabled : true,
            'maxDailySpendMinor' => $maxDailySpendMinor,
            'maxDailySpend' => AiMoney::decimalFromMinorUnits($maxDailySpendMinor),
            'spentTodayMinor' => $spentTodayMinor,
            'spentToday' => AiMoney::decimalFromMinorUnits($spentTodayMinor),
            'reservedTodayMinor' => $reservedTodayMinor,
            'reservedToday' => AiMoney::decimalFromMinorUnits($reservedTodayMinor),
            'spendPercent' => $maxDailySpendMinor > 0
                ? min(100, intdiv(($spentTodayMinor + $reservedTodayMinor) * 100, $maxDailySpendMinor))
                : 0,
            'runsCountToday' => $runsCountToday,
            'failedRunsCountToday' => $failedRunsCountToday,
            'providers' => $providers,
            'safety' => $safety,
        ];
    }

    public function toggleKillSwitch(): void
    {
        $user = Auth::user();
        $context = app(OrganizationContext::class);
        $authorizer = app(OrganizationAuthorizer::class);

        if (! $user || ! $authorizer->allows($user, $context->organization(), OrganizationPermission::ManageAiProviders)) {
            Notification::make()->title('Недостаточно прав')->danger()->send();

            return;
        }

        $safety = AiOrganizationSafetyControl::query()
            ->where('organization_id', $context->id())
            ->first();
        $enabled = ! ($safety === null ? true : (bool) $safety->is_ai_globally_enabled);
        $safety = app(UpdateAiSafetyControl::class)->handle($user, [
            'is_ai_globally_enabled' => $enabled,
        ]);

        Notification::make()
            ->title($safety->is_ai_globally_enabled ? 'AI включён' : 'AI временно отключён')
            ->success()
            ->send();
    }

    private function fillSafetyForm(): void
    {
        $control = AiOrganizationSafetyControl::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->first();
        $current = $control ?? new AiOrganizationSafetyControl;

        $this->form->fill([
            'is_ai_globally_enabled' => (bool) $current->is_ai_globally_enabled,
            'max_daily_spend' => AiMoney::decimalFromMinorUnits((int) $current->max_daily_spend_minor_units),
            'max_tokens_per_run' => (int) $current->max_tokens_per_run,
            'max_runs_per_minute' => (int) $current->max_runs_per_minute,
            'max_tool_calls_per_run' => (int) $current->max_tool_calls_per_run,
            'default_timeout_seconds' => (int) $current->default_timeout_seconds,
            'max_failover_attempts' => (int) $current->max_failover_attempts,
            'disabled_capabilities' => $current->disabled_capabilities ?? [],
            'disabled_providers' => $current->disabled_providers ?? [],
            'disabled_tools' => $current->disabled_tools ?? [],
        ]);
    }

    /** @return array<string, string> */
    private static function capabilityOptions(): array
    {
        return array_map(
            static fn ($definition): string => $definition->displayName,
            AiCapabilityRegistry::all(),
        );
    }

    /** @return array<string, string> */
    private static function toolOptions(): array
    {
        $tools = [];
        foreach (AiCapabilityRegistry::all() as $definition) {
            foreach ($definition->allowedTools as $tool) {
                $tools[$tool] = $tool;
            }
        }

        return $tools;
    }
}
