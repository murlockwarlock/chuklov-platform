<?php

namespace App\Modules\Scenarios\Domain\Enums;

enum ScenarioEventType: string
{
    case BookingCreated = 'booking.created';
    case BookingConfirmed = 'booking.confirmed';
    case BookingRescheduled = 'booking.rescheduled';
    case BookingCancelled = 'booking.cancelled';
    case BookingCompleted = 'booking.completed';
    case OnboardingStarted = 'onboarding.started';
    case FinancialObligationCreated = 'finance.obligation.created';
    case SurveyCompleted = 'survey.completed';
    case TestStagnationDetected = 'TEST_STAGNATION_DETECTED';
    case B2bLeadSubmitted = 'b2b.lead.submitted';
    case B2bSalesCallReady = 'b2b.sales_call.ready';
}
