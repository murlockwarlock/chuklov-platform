<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Application\Data\BookingStatusAnalyticsData;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
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

    public function handle(User $actor, DashboardPeriod $period): BookingStatusAnalyticsData
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewScheduling);

        $rows = DB::table((new BookingEvent)->getTable())
            ->where('organization_id', (int) $organization->getKey())
            ->where('occurred_at', '>=', $period->startUtc)
            ->where('occurred_at', '<', $period->endUtc)
            ->whereIn('event_type', array_keys(self::Labels))
            ->select('event_type')
            ->selectRaw('COUNT(DISTINCT booking_id) as booking_count')
            ->groupBy('event_type')
            ->pluck('booking_count', 'event_type');

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
