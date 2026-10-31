<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\Data\SchedulingAnalyticsData;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Enums\BookingEventType;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class SchedulingAnalytics
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    public function handle(User $actor, DashboardPeriod $period, ?Client $client = null): SchedulingAnalyticsData
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewScheduling);

        $organizationId = (int) $organization->getKey();
        if ($client instanceof Client && (int) $client->organization_id !== $organizationId) {
            abort(404);
        }
        $clientId = $client?->getKey();
        $bookingTable = (new Booking)->getTable();
        $eventTable = (new BookingEvent)->getTable();

        $bookingTotals = DB::table($bookingTable)
            ->where('organization_id', $organizationId)
            ->where('created_at', '>=', $period->startUtc)
            ->where('created_at', '<', $period->endUtc)
            ->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId))
            ->selectRaw('COUNT(*) as bookings')
            ->selectRaw('COALESCE(SUM(CASE WHEN visit_format = ? THEN 1 ELSE 0 END), 0) as home_requests', [VisitFormat::HomeVisit->value])
            ->first();

        $eventTotals = DB::table($eventTable.' as events')
            ->join($bookingTable.' as bookings', function (JoinClause $join): void {
                $join
                    ->on('bookings.organization_id', '=', 'events.organization_id')
                    ->on('bookings.id', '=', 'events.booking_id');
            })
            ->where('events.organization_id', $organizationId)
            ->where('events.occurred_at', '>=', $period->startUtc)
            ->where('events.occurred_at', '<', $period->endUtc)
            ->when($clientId !== null, fn ($query) => $query->where('bookings.client_id', $clientId))
            ->selectRaw('COALESCE(SUM(CASE WHEN events.event_type = ? THEN 1 ELSE 0 END), 0) as cancellations', [BookingEventType::Cancelled->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN events.event_type = ? THEN 1 ELSE 0 END), 0) as reschedules', [BookingEventType::Rescheduled->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN events.event_type = ? THEN 1 ELSE 0 END), 0) as no_shows', [BookingEventType::NoShow->value])
            ->selectRaw('COUNT(DISTINCT CASE WHEN events.event_type = ? THEN events.booking_id END) as visits', [BookingEventType::Completed->value])
            ->first();

        $retention = $this->retention($organizationId, $period, $bookingTable, $eventTable, $client);
        $retentionDenominator = (int) ($retention->total_clients ?? 0);
        $retainedClients = (int) ($retention->retained_clients ?? 0);

        return new SchedulingAnalyticsData(
            bookings: (int) ($bookingTotals->bookings ?? 0),
            cancellations: (int) ($eventTotals->cancellations ?? 0),
            reschedules: (int) ($eventTotals->reschedules ?? 0),
            noShows: (int) ($eventTotals->no_shows ?? 0),
            visits: (int) ($eventTotals->visits ?? 0),
            homeRequests: (int) ($bookingTotals->home_requests ?? 0),
            retainedClients: $retainedClients,
            notRetainedClients: max(0, $retentionDenominator - $retainedClients),
        );
    }

    private function retention(int $organizationId, DashboardPeriod $period, string $bookingTable, string $eventTable, ?Client $client = null): object
    {
        $clientId = $client?->getKey();
        $completedClients = DB::query()
            ->from($eventTable.' as completed_events')
            ->join($bookingTable.' as completed_bookings', function (JoinClause $join): void {
                $join
                    ->on('completed_bookings.organization_id', '=', 'completed_events.organization_id')
                    ->on('completed_bookings.id', '=', 'completed_events.booking_id');
            })
            ->where('completed_events.organization_id', $organizationId)
            ->where('completed_events.event_type', BookingEventType::Completed->value)
            ->where('completed_events.occurred_at', '>=', $period->startUtc)
            ->where('completed_events.occurred_at', '<', $period->endUtc)
            ->when($clientId !== null, fn ($query) => $query->where('completed_bookings.client_id', $clientId))
            ->select('completed_bookings.organization_id', 'completed_bookings.client_id')
            ->selectRaw('MAX(completed_events.occurred_at) as latest_completed_at')
            ->groupBy('completed_bookings.organization_id', 'completed_bookings.client_id');

        $result = DB::query()
            ->fromSub($completedClients, 'completed_clients')
            ->leftJoin($bookingTable.' as future_bookings', function (JoinClause $join) use ($period): void {
                $join
                    ->on('future_bookings.organization_id', '=', 'completed_clients.organization_id')
                    ->on('future_bookings.client_id', '=', 'completed_clients.client_id')
                    ->whereIn('future_bookings.status', BookingStatus::qualifyingFutureValues())
                    ->where('future_bookings.starts_at', '>', $period->nowUtc)
                    ->whereColumn('future_bookings.starts_at', '>', 'completed_clients.latest_completed_at');
            })
            ->selectRaw('COUNT(DISTINCT completed_clients.client_id) as total_clients')
            ->selectRaw('COUNT(DISTINCT CASE WHEN future_bookings.id IS NOT NULL THEN completed_clients.client_id END) as retained_clients')
            ->first();

        return $result ?? (object) [
            'total_clients' => 0,
            'retained_clients' => 0,
        ];
    }
}
