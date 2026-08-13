<?php

namespace App\Modules\Organizations\Domain\Enums;

enum OrganizationSettingKey: string
{
    case DefaultLanguage = 'default_language';
    case DefaultTimezone = 'default_timezone';
    case BookingLeadTimeMinutes = 'booking_lead_time_minutes';
    case BookingCancellationCutoffMinutes = 'booking_cancellation_cutoff_minutes';
    case HomeVisitTransportDepositAmountMinor = 'home_visit_transport_deposit_amount_minor';
    case HomeVisitTransportDepositCurrency = 'home_visit_transport_deposit_currency';
    case OfficeLocation = 'office_location';

    public function type(): OrganizationSettingType
    {
        return match ($this) {
            self::BookingLeadTimeMinutes,
            self::BookingCancellationCutoffMinutes,
            self::HomeVisitTransportDepositAmountMinor => OrganizationSettingType::Integer,
            default => OrganizationSettingType::String,
        };
    }
}
