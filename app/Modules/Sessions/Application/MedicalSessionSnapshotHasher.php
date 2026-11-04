<?php

namespace App\Modules\Sessions\Application;

use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionPain;
use App\Modules\Sessions\Domain\Models\MedicalSessionRootCause;
use App\Modules\Sessions\Domain\Models\MedicalSessionTestResult;

final class MedicalSessionSnapshotHasher
{
    public function forSession(MedicalSession $session): string
    {
        return hash('sha256', json_encode([
            'id' => (int) $session->getKey(),
            'organization_id' => (int) $session->organization_id,
            'client_id' => (int) $session->client_id,
            'specialist_id' => (int) $session->specialist_id,
            'booking_id' => $session->booking_id === null ? null : (int) $session->booking_id,
            'pain' => $session->getRawOriginal('pain'),
            'pain_vas' => $session->getRawOriginal('pain_vas'),
            'tests' => $session->getRawOriginal('tests'),
            'observations' => $session->getRawOriginal('observations'),
            'subjective_feelings' => $session->getRawOriginal('subjective_feelings'),
            'visual_posture_diagnostics' => $session->getRawOriginal('visual_posture_diagnostics'),
            'root_cause_hypothesis' => $session->getRawOriginal('root_cause_hypothesis'),
            'protocol' => $session->getRawOriginal('protocol'),
            'result' => $session->getRawOriginal('result'),
            'causes_manual_override' => (bool) $session->causes_manual_override,
            'encryption_key_version' => (int) $session->encryption_key_version,
            'pains' => MedicalSessionPain::query()
                ->where('organization_id', $session->organization_id)
                ->where('medical_session_id', $session->getKey())
                ->orderBy('sort_order')
                ->orderBy('id')
                ->with('problemTags:id,organization_id,name')
                ->get(['id', 'lineage_key', 'name', 'frequency_mode', 'frequency_count', 'frequency_period_unit', 'time_of_day', 'before_score', 'after_score', 'encryption_key_version'])
                ->map(static fn (MedicalSessionPain $pain): array => [
                    ...$pain->getAttributes(),
                    'tag_ids' => $pain->problemTags->modelKeys(),
                ])
                ->all(),
            'test_results' => MedicalSessionTestResult::query()
                ->where('organization_id', $session->organization_id)
                ->where('medical_session_id', $session->getKey())
                ->orderBy('medical_test_definition_id')
                ->get(['id', 'medical_test_definition_id', 'before_value_id', 'after_value_id'])
                ->map(static fn (MedicalSessionTestResult $result): array => $result->getAttributes())
                ->all(),
            'root_causes' => MedicalSessionRootCause::query()
                ->where('organization_id', $session->organization_id)
                ->where('medical_session_id', $session->getKey())
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'medical_cause_definition_id', 'free_text', 'encryption_key_version'])
                ->map(static fn (MedicalSessionRootCause $rootCause): array => $rootCause->getAttributes())
                ->all(),
            'causes' => $session->causes()
                ->orderBy('medical_cause_definitions.id')
                ->pluck('medical_cause_definitions.id')
                ->values()
                ->all(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
