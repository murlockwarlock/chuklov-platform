<?php

namespace App\Modules\Scenarios\Domain\Enums;

enum ScenarioEventType: string
{
    case BookingCreated = 'booking.created';
    case BookingConfirmed = 'booking.confirmed';
    case BookingRescheduled = 'booking.rescheduled';
    case BookingCancelled = 'booking.cancelled';
    case BookingRejected = 'booking.rejected';
    case BookingCompleted = 'booking.completed';
    case OnboardingStarted = 'onboarding.started';
    case FinancialObligationCreated = 'finance.obligation.created';
    case SurveyCompleted = 'survey.completed';
    case TestStagnationDetected = 'TEST_STAGNATION_DETECTED';
    case B2bLeadSubmitted = 'b2b.lead.submitted';
    case B2bSalesCallReady = 'b2b.sales_call.ready';
    case CompanionRequestedSpecialist = 'companion.requested_specialist';
    case CompanionFallbackFailed = 'companion.fallback_failed';
    case BroadcastDeliveryFailed = 'broadcast.delivery_failed';
    case ClientFeedbackSubmitted = 'feedback.submitted';
    case PayoutRequested = 'referral.payout.requested';
    case PayoutStatusChanged = 'referral.payout.status_changed';
    case HomeVisitChanged = 'booking.home_visit.changed';
    case AiEvaluationFailed = 'ai.evaluation.failed';
    case KnowledgeIngestionFailed = 'knowledge.ingestion.failed';
    case ReferralLinkVisited = 'referral.link.visited';
    case PaymentProviderEventPrepared = 'payment.provider.event.prepared';
    case TrackerDailyTaskAssigned = 'tracker.task.daily_assigned';
    case TrackerWeeklyTaskAssigned = 'tracker.task.weekly_assigned';
}
