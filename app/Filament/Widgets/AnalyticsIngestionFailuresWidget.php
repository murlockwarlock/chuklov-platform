<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\KnowledgeIngestionAnalytics;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class AnalyticsIngestionFailuresWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Обработка базы знаний';

    protected ?string $description = 'Только агрегированное число неуспешных операций';

    protected static ?int $sort = 7;

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
                OrganizationPermission::ViewKnowledge,
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

        return app(KnowledgeIngestionAnalytics::class)->handle($actor, $period);
    }

    /** @return array<Stat> */
    protected function getStats(): array
    {
        return [
            Stat::make('Ошибки обработки знаний', (string) ($this->getData() ?? 0))
                ->description('Неуспешные операции базы знаний за период'),
        ];
    }
}
