<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\Data\ServiceVisitAnalyticsData;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use Illuminate\Support\Facades\DB;

final readonly class ServiceVisitAnalytics
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
    ) {}

    public function handle(User $actor, DashboardPeriod $period, ?Client $client = null): ServiceVisitAnalyticsData
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewScheduling);
        $organizationId = (int) $organization->getKey();
        if ($client instanceof Client && (int) $client->organization_id !== $organizationId) {
            abort(404);
        }
        $clientId = $client?->getKey();
        $rows = DB::table('bookings')
            ->join('services', function ($join): void {
                $join->on('services.organization_id', '=', 'bookings.organization_id')
                    ->on('services.id', '=', 'bookings.service_id');
            })
            ->where('bookings.organization_id', $organizationId)
            ->where('bookings.status', BookingStatus::Completed->value)
            ->where('bookings.starts_at', '>=', $period->startUtc)
            ->where('bookings.starts_at', '<', $period->endUtc)
            ->when($clientId !== null, fn ($query) => $query->where('bookings.client_id', $clientId))
            ->select('services.name')
            ->selectRaw('COUNT(*) as visit_count')
            ->selectRaw('COUNT(DISTINCT bookings.client_id) as client_count')
            ->groupBy('services.name')
            ->orderByDesc('visit_count')
            ->orderBy('services.name')
            ->get()
            ->map(static fn (object $row): array => [
                'name' => (string) $row->name,
                'visit_count' => (int) $row->visit_count,
                'client_count' => (int) $row->client_count,
            ])
            ->all();
        $specialistRows = DB::table('bookings')
            ->join('specialists', function ($join): void {
                $join->on('specialists.organization_id', '=', 'bookings.organization_id')
                    ->on('specialists.id', '=', 'bookings.specialist_id');
            })
            ->where('bookings.organization_id', $organizationId)
            ->where('bookings.status', BookingStatus::Completed->value)
            ->where('bookings.starts_at', '>=', $period->startUtc)
            ->where('bookings.starts_at', '<', $period->endUtc)
            ->when($clientId !== null, fn ($query) => $query->where('bookings.client_id', $clientId))
            ->select('specialists.id', 'specialists.display_name')
            ->selectRaw('COUNT(*) as visit_count')
            ->selectRaw('COUNT(DISTINCT bookings.client_id) as client_count')
            ->groupBy('specialists.id', 'specialists.display_name')
            ->orderByDesc('visit_count')
            ->orderBy('specialists.display_name')
            ->get()
            ->map(static fn (object $row): array => [
                'name' => (string) $row->display_name,
                'visit_count' => (int) $row->visit_count,
                'client_count' => (int) $row->client_count,
            ])
            ->all();

        return new ServiceVisitAnalyticsData(
            visitCount: array_sum(array_column($rows, 'visit_count')),
            services: array_values($rows),
            specialists: array_values($specialistRows),
        );
    }
}
