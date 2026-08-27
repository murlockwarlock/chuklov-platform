<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Modules\Analytics\Application\AcquisitionAnalytics;
use App\Modules\Analytics\Application\Data\AcquisitionAnalyticsData;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class AnalyticsAcquisitionWidget extends Widget
{
    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.analytics-acquisition-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 3;

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
                OrganizationPermission::ViewClients,
            );
        } catch (\Throwable) {
            return false;
        }
    }

    public function getData(): ?AcquisitionAnalyticsData
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            return null;
        }

        $organization = app(OrganizationContext::class)->organization();
        $period = DashboardPeriod::fromFilters($this->pageFilters, $organization->defaultTimezone());

        return app(AcquisitionAnalytics::class)->handle($actor, $period);
    }
}
