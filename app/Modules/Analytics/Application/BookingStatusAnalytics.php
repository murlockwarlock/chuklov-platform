<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Application\Data\BookingStatusAnalyticsData;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class BookingStatusAnalytics
{
    private const array Labels = [
        'completed' => 'Завершённые',
        'rescheduled' => 'Перенесённые',
        'cancelled' => 'Отменённые',
    ];

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    public function handle(User $actor, DashboardPeriod $period, ?Client $client = null): BookingStatusAnalyticsData
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewScheduling);
        $clientId = $client?->getKey();

        $rows = DB::table((new BookingEvent)->getTable().' as events')
            ->join((new Booking)->getTable().' as bookings', function (JoinClause $join): void {
                $join
                    ->on('bookings.organization_id', '=', 'events.organization_id')
                    ->on('bookings.id', '=', 'events.booking_id');
            })
            ->where('events.organization_id', (int) $organization->getKey())
            ->where('events.occurred_at', '>=', $period->startUtc)
            ->where('events.occurred_at', '<', $period->endUtc)
            ->whereIn('events.event_type', array_keys(self::Labels))
            ->when($clientId !== null, fn ($query) => $query->where('bookings.client_id', $clientId))
            ->select('events.event_type')
            ->selectRaw('COUNT(DISTINCT events.booking_id) as booking_count')
            ->groupBy('events.event_type')
            ->pluck('booking_count', 'events.event_type');

        $statuses = [];
        foreach (self::Labels as $key => $label) {
            $statuses[] = [
                'key' => $key,
                'label' => $label,
                'count' => (int) ($rows[$key] ?? 0),
            ];
        }

        return new BookingStatusAnalyticsData($statuses);
    }
}
