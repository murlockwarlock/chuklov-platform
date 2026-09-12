<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;

final class ScenarioEventPermissionPolicy
{
    public function allows(
        ScenarioEventType $eventType,
        int $organizationId,
        OrganizationMembership $membership,
        ?OrganizationPermission $requiredPermission = null,
    ): bool {
        if ((int) $membership->organization_id !== $organizationId || ! $membership->is_active) {
            return false;
        }

        $permission = $requiredPermission ?? $this->requiredPermission($eventType);

        return $permission === null || $membership->role->allows($permission);
    }

    private function requiredPermission(ScenarioEventType $eventType): ?OrganizationPermission
    {
        return match ($eventType) {
            ScenarioEventType::BookingCreated,
            ScenarioEventType::BookingConfirmed,
            ScenarioEventType::BookingRescheduled,
            ScenarioEventType::BookingCancelled,
            ScenarioEventType::BookingRejected,
            ScenarioEventType::BookingCompleted,
            ScenarioEventType::HomeVisitChanged => OrganizationPermission::ViewScheduling,
            ScenarioEventType::FinancialObligationCreated,
            ScenarioEventType::PayoutRequested,
            ScenarioEventType::PayoutStatusChanged => OrganizationPermission::ViewFinance,
            ScenarioEventType::SurveyCompleted,
            ScenarioEventType::TestStagnationDetected => OrganizationPermission::ViewSurveys,
            ScenarioEventType::B2bLeadSubmitted,
            ScenarioEventType::B2bSalesCallReady => OrganizationPermission::ViewB2bLeads,
            ScenarioEventType::CompanionRequestedSpecialist,
            ScenarioEventType::CompanionFallbackFailed => OrganizationPermission::ManageCompanionHandoff,
            ScenarioEventType::AiEvaluationFailed => OrganizationPermission::ViewAiRuns,
            ScenarioEventType::KnowledgeIngestionFailed => OrganizationPermission::ViewKnowledge,
            ScenarioEventType::BroadcastDeliveryFailed => OrganizationPermission::ViewScenarios,
            ScenarioEventType::ClientFeedbackSubmitted => OrganizationPermission::ViewClients,
            default => null,
        };
    }
}
