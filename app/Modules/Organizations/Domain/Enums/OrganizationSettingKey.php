<?php

namespace App\Modules\Organizations\Domain\Enums;

enum OrganizationSettingKey: string
{
    case DefaultLanguage = 'default_language';
    case DefaultTimezone = 'default_timezone';
    case BookingLeadTimeMinutes = 'booking_lead_time_minutes';
    case BookingCancellationCutoffMinutes = 'booking_cancellation_cutoff_minutes';
    case B2bSalesCallDurationMinutes = 'b2b_sales_call_duration_minutes';
    case B2bZoomHostLicensed = 'b2b_zoom_host_licensed';
    case HomeVisitTransportDepositAmountMinor = 'home_visit_transport_deposit_amount_minor';
    case HomeVisitTransportDepositCurrency = 'home_visit_transport_deposit_currency';
    case OfficeLocation = 'office_location';
    case CompanionContextFirstExchanges = 'companion_context_first_exchanges';
    case CompanionContextRecentExchanges = 'companion_context_recent_exchanges';

    public function type(): OrganizationSettingType
    {
        return match ($this) {
            self::BookingLeadTimeMinutes,
            self::BookingCancellationCutoffMinutes,
            self::B2bSalesCallDurationMinutes,
            self::HomeVisitTransportDepositAmountMinor,
            self::CompanionContextFirstExchanges,
            self::CompanionContextRecentExchanges => OrganizationSettingType::Integer,
            self::B2bZoomHostLicensed => OrganizationSettingType::Boolean,
            default => OrganizationSettingType::String,
        };
    }
}
