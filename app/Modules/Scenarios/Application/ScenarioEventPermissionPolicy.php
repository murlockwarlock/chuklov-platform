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
    ): bool {
        if ((int) $membership->organization_id !== $organizationId || ! $membership->is_active) {
            return false;
        }

        $permission = $this->requiredPermission($eventType);

        return $permission === null || $membership->role->allows($permission);
    }

    private function requiredPermission(ScenarioEventType $eventType): ?OrganizationPermission
    {
        return match ($eventType) {
            ScenarioEventType::CompanionRequestedSpecialist => OrganizationPermission::ManageCompanionHandoff,
            default => null,
        };
    }
}
