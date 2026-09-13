<?php

namespace App\Modules\Sessions\Application;

use App\Modules\Sessions\Domain\Models\MedicalSession;

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
            'tests' => $session->getRawOriginal('tests'),
            'observations' => $session->getRawOriginal('observations'),
            'root_cause_hypothesis' => $session->getRawOriginal('root_cause_hypothesis'),
            'protocol' => $session->getRawOriginal('protocol'),
            'result' => $session->getRawOriginal('result'),
            'encryption_key_version' => (int) $session->encryption_key_version,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
