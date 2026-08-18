<?php

namespace App\Filament\Pages;

use App\Modules\AI\Application\Actions\UpdateAiSafetyControl;
use App\Modules\AI\Domain\Models\AiOrganizationDailyBudget;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use BackedEnum;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class AiMonitoringOverview extends Page
{
    public const int PROVIDER_OVERVIEW_LIMIT = 50;

    protected static ?string $title = 'Мониторинг и безопасность AI';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?string $navigationLabel = 'Мониторинг и безопасность';

    protected static string|\UnitEnum|null $navigationGroup = 'Искусственный интеллект';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.ai-monitoring-overview';

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
        return 'Мониторинг AI и безопасность';
    }

    public function getSubheading(): ?string
    {
        return 'Клинический пульт управления: статус провайдеров, бюджет, лимиты и аварийное отключение (Kill-Switch).';
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

        $providers = AiProviderConfiguration::query()
            ->where('organization_id', $orgId)
            ->withCount('models')
            ->orderBy('id')
            ->limit(self::PROVIDER_OVERVIEW_LIMIT)
            ->get();

        return [
            'isAiEnabled' => $safety !== null ? $safety->is_ai_globally_enabled : true,
            'maxDailySpendMinor' => $safety !== null ? $safety->max_daily_spend_minor_units : 5000,
            'spentTodayMinor' => $budget !== null ? $budget->spent_minor_units : 0,
            'reservedTodayMinor' => $budget !== null ? $budget->reserved_minor_units : 0,
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
            ->title($safety->is_ai_globally_enabled ? 'AI сервис включен' : 'AI сервис аварийно отключен (Kill-Switch активирован)')
            ->success()
            ->send();
    }
}
