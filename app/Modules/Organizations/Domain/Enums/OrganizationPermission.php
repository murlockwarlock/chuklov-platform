<?php

namespace App\Modules\Organizations\Domain\Enums;

enum OrganizationPermission: string
{
    case ViewAdmin = 'view_admin';
    case ViewHorizon = 'view_horizon';
    case ViewClients = 'view_clients';
    case ManageClients = 'manage_clients';
    case RecordConsent = 'record_consent';
    case ManageServices = 'manage_services';
    case ViewSpecialists = 'view_specialists';
    case ManageSpecialists = 'manage_specialists';
    case ManageContent = 'manage_content';
    case ManageSettings = 'manage_settings';
    case ManageFeatures = 'manage_features';
    case ManageCredentials = 'manage_credentials';
    case ViewAuditEvents = 'view_audit_events';
    case ViewScheduling = 'view_scheduling';
    case ManageScheduling = 'manage_scheduling';
    case ViewScenarios = 'view_scenarios';
    case ManageScenarios = 'manage_scenarios';
    case ViewSurveys = 'view_surveys';
    case ManageSurveys = 'manage_surveys';
    case ViewKnowledge = 'view_knowledge';
    case ManageKnowledge = 'manage_knowledge';
    case ViewFinance = 'view_finance';
    case ManageFinance = 'manage_finance';
    case ViewAiRuns = 'view_ai_runs';
    case ViewAiTrace = 'view_ai_trace';
    case ReviewAiProposals = 'review_ai_proposals';
    case ManageAiPrompts = 'manage_ai_prompts';
    case ActivateAiReleases = 'activate_ai_releases';
    case ManageAiProviders = 'manage_ai_providers';
    case UseAiPlayground = 'use_ai_playground';
}
