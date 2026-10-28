<?php

namespace App\Modules\MedicalProfiles\Application;

use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfileHistoryEntry;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfilePain;

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
            'complaints' => $profile->getRawOriginal('complaints'),
            'goals' => $profile->getRawOriginal('goals'),
            'operations' => $profile->getRawOriginal('operations'),
            'injuries' => $profile->getRawOriginal('injuries'),
            'chronic_conditions' => $profile->getRawOriginal('chronic_conditions'),
            'clinical_answers' => $profile->getRawOriginal('clinical_answers'),
            'client_confirmed_at' => $profile->getRawOriginal('client_confirmed_at'),
            'medicines' => $profile->getRawOriginal('medicines'),
            'supplements' => $profile->getRawOriginal('supplements'),
            'encryption_key_version' => (int) $profile->encryption_key_version,
            'pains' => MedicalProfilePain::query()
                ->where('organization_id', $profile->organization_id)
                ->where('medical_profile_id', $profile->getKey())
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'lineage_key', 'name', 'frequency_mode', 'frequency_count', 'frequency_period_unit', 'time_of_day', 'score', 'encryption_key_version'])
                ->map(static fn (MedicalProfilePain $pain): array => $pain->getAttributes())
                ->all(),
            'history_entries' => MedicalProfileHistoryEntry::query()
                ->where('organization_id', $profile->organization_id)
                ->where('medical_profile_id', $profile->getKey())
                ->orderBy('entry_type')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'lineage_key', 'entry_type', 'year', 'description', 'encryption_key_version'])
                ->map(static fn (MedicalProfileHistoryEntry $entry): array => $entry->getAttributes())
                ->all(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
