<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Application\Data\DashboardUpcomingBookingsResult;
use App\Modules\Scheduling\Application\Data\DashboardUpcomingDay;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final readonly class GetDashboardUpcomingBookings
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
    ) {}

    public function handle(User $actor): DashboardUpcomingBookingsResult
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewScheduling);
        $orgId = (int) $organization->getKey();

        $tz = app(ResolveSpecialistViewerTimezone::class)->forUser($actor);
        $now = CarbonImmutable::now($tz);
        $todayStart = $now->startOfDay();

        $excludedStatuses = [
            BookingStatus::Cancelled->value,
            BookingStatus::Rejected->value,
            BookingStatus::NoShow->value,
        ];

        $daysData = [];
        $todayCount = 0;
        $tomorrowCount = 0;

        for ($i = 0; $i < 4; $i++) {
            $dayStartLocal = $todayStart->addDays($i);
            $dayEndLocal = $dayStartLocal->addDay();

            $query = Booking::query()
                ->where('organization_id', $orgId)
                ->where('starts_at', '>=', $dayStartLocal->setTimezone('UTC')->toDateTimeString())
                ->where('starts_at', '<', $dayEndLocal->setTimezone('UTC')->toDateTimeString())
                ->whereNotIn('status', $excludedStatuses);

            $dayTotalCount = (clone $query)->count();

            if ($i === 0) {
                $todayCount = $dayTotalCount;
            } elseif ($i === 1) {
                $tomorrowCount = $dayTotalCount;
            }

            /** @var Collection<int, Booking> $bookings */
            $bookings = $query
                ->select([
                    'id',
                    'organization_id',
                    'client_id',
                    'specialist_id',
                    'service_id',
                    'starts_at',
                    'visit_format',
                    'status',
                    'location',
                    'location_area',
                    'location_snapshot',
                ])
                ->with([
                    'client:id,full_name',
                    'specialist:id,display_name',
                    'service:id,name',
                ])
                ->orderBy('starts_at', 'asc')
                ->orderBy('id', 'asc')
                ->limit(10)
                ->get();

            $daysData[] = new DashboardUpcomingDay(
                date: $dayStartLocal,
                label: self::formatDayLabel($dayStartLocal, $i),
                isToday: $i === 0,
                isTomorrow: $i === 1,
                totalCount: $dayTotalCount,
                bookings: $bookings,
            );
        }

        return new DashboardUpcomingBookingsResult(
            todayCount: $todayCount,
            tomorrowCount: $tomorrowCount,
            days: $daysData,
            timezone: $tz,
        );
    }

    private static function formatDayLabel(CarbonImmutable $date, int $dayIndex): string
    {
        $months = [
            1 => 'янв.', 2 => 'февр.', 3 => 'марта', 4 => 'апр.',
            5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'авг.',
            9 => 'сент.', 10 => 'окт.', 11 => 'нояб.', 12 => 'дек.',
        ];
        $weekdays = [
            1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг',
            5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье',
        ];

        $dayNum = $date->day;
        $monthName = $months[$date->month] ?? '';

        if ($dayIndex === 0) {
            return "Сегодня · {$dayNum} {$monthName}";
        }

        if ($dayIndex === 1) {
            return "Завтра · {$dayNum} {$monthName}";
        }

        $weekday = $weekdays[$date->dayOfWeekIso] ?? '';

        return "{$weekday} · {$dayNum} {$monthName}";
    }
}
