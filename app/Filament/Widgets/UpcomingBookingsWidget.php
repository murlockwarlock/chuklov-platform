<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Application\Data\DashboardUpcomingBookingsResult;
use App\Modules\Scheduling\Application\GetDashboardUpcomingBookings;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class UpcomingBookingsWidget extends Widget
{
    protected string $view = 'filament.widgets.upcoming-bookings-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

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

    public function getData(): ?DashboardUpcomingBookingsResult
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            return null;
        }

        return app(GetDashboardUpcomingBookings::class)->handle($actor);
    }

    public static function statusLabel(BookingStatus|string $status): string
    {
        $status = $status instanceof BookingStatus ? $status : BookingStatus::tryFrom($status);

        return match ($status) {
            BookingStatus::Requested => 'Ожидает подтверждения',
            BookingStatus::PendingReview => 'На рассмотрении',
            BookingStatus::Confirmed => 'Подтверждена',
            BookingStatus::Completed => 'Завершена',
            default => '—',
        };
    }

    public static function statusColor(BookingStatus|string $status): string
    {
        $status = $status instanceof BookingStatus ? $status : BookingStatus::tryFrom($status);

        return match ($status) {
            BookingStatus::Confirmed => 'text-emerald-700 bg-emerald-50 border-emerald-200 dark:text-emerald-300 dark:bg-emerald-950/60 dark:border-emerald-800',
            BookingStatus::Requested, BookingStatus::PendingReview => 'text-amber-800 bg-amber-50 border-amber-200 dark:text-amber-300 dark:bg-amber-950/60 dark:border-amber-800',
            BookingStatus::Completed => 'text-gray-600 bg-gray-100 border-gray-200 dark:text-gray-400 dark:bg-white/5 dark:border-white/10',
            default => 'text-gray-600 bg-gray-50 border-gray-200 dark:text-gray-400 dark:bg-white/5 dark:border-white/10',
        };
    }

    public static function formatLabel(VisitFormat|string $format): string
    {
        $format = $format instanceof VisitFormat ? $format : VisitFormat::tryFrom($format);

        return match ($format) {
            VisitFormat::Office => 'В клинике',
            VisitFormat::HomeVisit => 'Выезд',
            VisitFormat::Online => 'Онлайн',
            default => 'Визит',
        };
    }
}
