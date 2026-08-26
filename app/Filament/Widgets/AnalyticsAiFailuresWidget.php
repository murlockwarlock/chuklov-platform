<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Modules\Analytics\Application\AiFailureAnalytics;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class AnalyticsAiFailuresWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Состояние ИИ';

    protected ?string $description = 'Только агрегированное число неуспешных запусков';

    protected static ?int $sort = 6;

    public static function canView(): bool
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            return false;
        }

        try {
            $organization = app(OrganizationContext::class)->organization();

            return app(OrganizationAuthorizer::class)->allows(
                $actor,
                $organization,
                OrganizationPermission::ViewAiRuns,
            );
        } catch (\Throwable) {
            return false;
        }
    }

    public function getData(): ?int
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            return null;
        }

        $organization = app(OrganizationContext::class)->organization();
        $period = DashboardPeriod::fromFilters($this->pageFilters, $organization->defaultTimezone());

        return app(AiFailureAnalytics::class)->handle($actor, $period);
    }

    /** @return array<Stat> */
    protected function getStats(): array
    {
        return [
            Stat::make('Ошибки запусков ИИ', (string) ($this->getData() ?? 0))
                ->description('Неуспешные логические запуски за период'),
        ];
    }
}
