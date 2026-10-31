<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Application\Data\ClientAnalyticsInsightsData;
use App\Modules\Analytics\Domain\Enums\ClientSegment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class ClientAnalyticsInsights
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private ClientSegmentQuery $segments,
    ) {}

    public function handle(User $actor, Client $client): ClientAnalyticsInsightsData
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        abort_unless((int) $client->organization_id === (int) $organization->getKey(), 404);

        $organizationId = (int) $organization->getKey();
        $now = CarbonImmutable::now('UTC');
        $summary = DB::table('bookings')
            ->where('organization_id', $organizationId)
            ->where('client_id', $client->getKey())
            ->selectRaw('COUNT(*) as booking_count')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed_visit_count', [BookingStatus::Completed->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as no_show_count', [BookingStatus::NoShow->value])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) AND starts_at >= ? THEN 1 ELSE 0 END) as upcoming_booking_count', [
                BookingStatus::Requested->value,
                BookingStatus::Confirmed->value,
                $now,
            ])
            ->selectRaw('MAX(CASE WHEN status = ? THEN starts_at END) as last_completed_at', [BookingStatus::Completed->value])
            ->first();

        $services = DB::table('bookings')
            ->join('services', function ($join): void {
                $join->on('services.organization_id', '=', 'bookings.organization_id')
                    ->on('services.id', '=', 'bookings.service_id');
            })
            ->where('bookings.organization_id', $organizationId)
            ->where('bookings.client_id', $client->getKey())
            ->where('bookings.status', BookingStatus::Completed->value)
            ->select('services.name')
            ->selectRaw('COUNT(*) as visit_count')
            ->groupBy('services.name')
            ->orderByDesc('visit_count')
            ->orderBy('services.name')
            ->get()
            ->map(static fn (object $row): array => [
                'name' => (string) $row->name,
                'visit_count' => (int) $row->visit_count,
            ])
            ->values()
            ->all();

        $segments = [];
        foreach (ClientSegment::cases() as $segment) {
            if ($segment === ClientSegment::All) {
                continue;
            }

            if ($this->segments->query($segment)->whereKey($client->getKey())->exists()) {
                $segments[] = $segment->label();
            }
        }

        $lastCompletedAt = is_string($summary?->last_completed_at) && trim($summary->last_completed_at) !== ''
            ? CarbonImmutable::parse($summary->last_completed_at, 'UTC')
            : null;

        return new ClientAnalyticsInsightsData(
            segments: $segments,
            bookingCount: (int) ($summary->booking_count ?? 0),
            completedVisitCount: (int) ($summary->completed_visit_count ?? 0),
            noShowCount: (int) ($summary->no_show_count ?? 0),
            upcomingBookingCount: (int) ($summary->upcoming_booking_count ?? 0),
            lastCompletedAt: $lastCompletedAt,
            services: $services,
        );
    }
}
