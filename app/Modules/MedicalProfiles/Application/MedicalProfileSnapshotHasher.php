<?php

namespace App\Modules\MedicalProfiles\Application;

use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;

final class MedicalProfileSnapshotHasher
{
    public function forProfile(?MedicalProfile $profile): string
    {
        if (! $profile instanceof MedicalProfile) {
            return hash('sha256', json_encode([
                'exists' => false,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        return hash('sha256', json_encode([
            'exists' => true,
            'id' => (int) $profile->getKey(),
            'organization_id' => (int) $profile->organization_id,
            'client_id' => (int) $profile->client_id,
            'anamnesis' => $profile->getRawOriginal('anamnesis'),
            'complaints_goals' => $profile->getRawOriginal('complaints_goals'),
            'operations_injuries' => $profile->getRawOriginal('operations_injuries'),
            'medicines' => $profile->getRawOriginal('medicines'),
            'supplements' => $profile->getRawOriginal('supplements'),
            'encryption_key_version' => (int) $profile->encryption_key_version,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
