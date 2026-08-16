<?php

namespace App\Modules\Organizations\Domain\Enums;

enum OrganizationRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Staff = 'staff';

    public function allows(OrganizationPermission $permission): bool
    {
        return match ($this) {
            self::Owner => true,
            self::Administrator => true,
            self::Staff => in_array($permission, [
                OrganizationPermission::ViewClients,
                OrganizationPermission::ManageClients,
                OrganizationPermission::RecordConsent,
                OrganizationPermission::ViewSpecialists,
                OrganizationPermission::ViewScheduling,
                OrganizationPermission::ViewScenarios,
                OrganizationPermission::ViewSurveys,
                OrganizationPermission::ViewKnowledge,
                OrganizationPermission::ViewFinance,
                OrganizationPermission::ViewAiRuns,
                OrganizationPermission::ReviewAiProposals,
                OrganizationPermission::UseAiPlayground,
            ], true),
        };
    }

    public function canAccessAdminPanel(): bool
    {
        return in_array($this, [self::Owner, self::Administrator], true);
    }
}
