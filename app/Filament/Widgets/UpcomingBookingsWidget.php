<?php

namespace App\Filament\Widgets;

use App\Models\User;
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

    public function getData(): ?DashboardUpcomingBookingsResult
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            return null;
        }

        try {
            return app(GetDashboardUpcomingBookings::class)->handle($actor);
        } catch (\Throwable) {
            return null;
        }
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
            BookingStatus::Confirmed => 'text-emerald-700 bg-emerald-50 border-emerald-200',
            BookingStatus::Requested, BookingStatus::PendingReview => 'text-amber-800 bg-amber-50 border-amber-200',
            BookingStatus::Completed => 'text-slate-600 bg-slate-100 border-slate-200',
            default => 'text-slate-600 bg-slate-50 border-slate-200',
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
