<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Channels\Domain\Enums\NotificationSeverity;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;

final class ScenarioNotificationPresentation
{
    public static function severity(ScenarioEventType $eventType, ?string $kind = null): NotificationSeverity
    {
        if ($kind === 'appointment_reminder') {
            return NotificationSeverity::Action;
        }

        return match ($eventType) {
            ScenarioEventType::BookingCreated,
            ScenarioEventType::HomeVisitChanged,
            ScenarioEventType::PayoutRequested,
            ScenarioEventType::B2bLeadSubmitted,
            ScenarioEventType::CompanionRequestedSpecialist => NotificationSeverity::High,
            ScenarioEventType::BookingRescheduled,
            ScenarioEventType::BookingCancelled,
            ScenarioEventType::SurveyCompleted,
            ScenarioEventType::B2bSalesCallReady,
            ScenarioEventType::PayoutStatusChanged,
            ScenarioEventType::ClientFeedbackSubmitted => NotificationSeverity::Action,
            ScenarioEventType::CompanionFallbackFailed,
            ScenarioEventType::AiEvaluationFailed,
            ScenarioEventType::KnowledgeIngestionFailed,
            ScenarioEventType::BroadcastDeliveryFailed,
            ScenarioEventType::PaymentInitiationUnavailable,
            ScenarioEventType::PaymentReconciliationRequired,
            ScenarioEventType::FulfillmentFailed => NotificationSeverity::Critical,
            ScenarioEventType::PaymentFailed => NotificationSeverity::Action,
            default => NotificationSeverity::Info,
        };
    }
}
