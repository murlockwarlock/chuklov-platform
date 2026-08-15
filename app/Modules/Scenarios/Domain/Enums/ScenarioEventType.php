<?php

namespace App\Modules\Scenarios\Domain\Enums;

enum ScenarioEventType: string
{
    case BookingCompleted = 'booking.completed';
    case OnboardingStarted = 'onboarding.started';
    case FinancialObligationCreated = 'finance.obligation.created';
}
