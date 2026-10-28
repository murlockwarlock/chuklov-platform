<?php

namespace App\Modules\ClientPortal\Domain\Enums;

enum RoadMapAvailabilityMode: string
{
    case ReviewedCourseReport = 'reviewed_course_report';
    case ConfirmedBooking = 'confirmed_booking';
    case CompletedBooking = 'completed_booking';

    public function label(): string
    {
        return match ($this) {
            self::ReviewedCourseReport => __('После принятого отчёта'),
            self::ConfirmedBooking => __('После подтверждённой записи'),
            self::CompletedBooking => __('После завершённого сеанса'),
        };
    }
}
