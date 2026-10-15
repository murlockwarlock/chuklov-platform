<?php

namespace App\Modules\MedicalProfiles\Application;

use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;

final class MedicalProfileCompletion
{
    public function isComplete(int $organizationId, int $clientId): bool
    {
        return MedicalProfile::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $clientId)
            ->where(fn ($query) => $query->whereNotNull('completed_at')->orWhereNotNull('client_confirmed_at'))
            ->exists();
    }
}
