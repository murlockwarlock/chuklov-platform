<?php

namespace App\Modules\Organizations\Domain\Enums;

enum OrganizationSettingKey: string
{
    case DefaultLanguage = 'default_language';
    case DefaultTimezone = 'default_timezone';
    case BookingLeadTimeMinutes = 'booking_lead_time_minutes';

    public function type(): OrganizationSettingType
    {
        return match ($this) {
            self::BookingLeadTimeMinutes => OrganizationSettingType::Integer,
            default => OrganizationSettingType::String,
        };
    }
}
