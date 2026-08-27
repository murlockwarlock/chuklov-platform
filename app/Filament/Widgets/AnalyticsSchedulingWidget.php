<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\Data\SchedulingAnalyticsData;
use App\Modules\Analytics\Application\SchedulingAnalytics;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class AnalyticsSchedulingWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Записи и визиты';

    protected ?string $description = 'Операционные показатели за выбранный период';

    protected static ?int $sort = 4;

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
                OrganizationPermission::ViewScheduling,
            );
        } catch (\Throwable) {
            return false;
        }
    }

    public function getData(): ?SchedulingAnalyticsData
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            return null;
        }

        $organization = app(OrganizationContext::class)->organization();
        $period = DashboardPeriod::fromFilters($this->pageFilters, $organization->defaultTimezone());

        return app(SchedulingAnalytics::class)->handle($actor, $period);
    }

    /** @return array<Stat> */
    protected function getStats(): array
    {
        $data = $this->getData();

        return [
            Stat::make('Записи', (string) ($data === null ? 0 : $data->bookings)),
            Stat::make('Завершённые визиты', (string) ($data === null ? 0 : $data->visits)),
            Stat::make('Отмены', (string) ($data === null ? 0 : $data->cancellations)),
            Stat::make('Переносы', (string) ($data === null ? 0 : $data->reschedules)),
            Stat::make('Запросы на выезд', (string) ($data === null ? 0 : $data->homeRequests)),
            Stat::make('Записаны снова после визита', $this->retentionLabel($data))
                ->description($this->retentionDescription($data)),
        ];
    }

    private function retentionLabel(?SchedulingAnalyticsData $data): string
    {
        $rate = $data?->retentionRate();

        return $rate === null ? '—' : number_format($rate, 1, ',', ' ').'%';
    }

    private function retentionDescription(?SchedulingAnalyticsData $data): string
    {
        if ($data === null || $data->retentionRate() === null) {
            return 'Нет завершённых визитов за период';
        }

        return sprintf(
            '%d из %d клиентов',
            $data->retainedClients,
            $data->retentionDenominator(),
        );
    }
}
