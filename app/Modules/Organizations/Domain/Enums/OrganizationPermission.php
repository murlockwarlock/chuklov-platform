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
    case ViewFinance = 'view_finance';
    case ManageFinance = 'manage_finance';
}
