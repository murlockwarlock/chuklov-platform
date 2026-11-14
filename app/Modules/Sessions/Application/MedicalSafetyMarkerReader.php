<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Sessions\Domain\Models\MedicalClientSafetyMarker;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionSafetyMarker;
use DateTimeInterface;

final readonly class MedicalSafetyMarkerReader
{
    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private MedicalEncryptorInterface $encryptor,
    ) {}

    /** @return list<array<string, mixed>> */
    public function forClient(User $actor, Client $client, bool $includeCleared = false): array
    {
        $organization = $this->authorization->authorizeViewClient($actor, $client);
        $query = MedicalClientSafetyMarker::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->orderByDesc('activated_at')
            ->orderByDesc('id');
        if (! $includeCleared) {
            $query->where('is_active', true);
        }

        return $query->get()->map(fn (MedicalClientSafetyMarker $marker): array => $this->clientMarker($marker))->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function historyForClient(User $actor, Client $client): array
    {
        return $this->forClient($actor, $client, true);
    }

    /** @return list<array<string, mixed>> */
    public function forSession(User $actor, MedicalSession $session, ?Client $expectedClient = null): array
    {
        $organization = $this->authorization->authorizeView($actor, $session, $expectedClient);

        $markers = MedicalSessionSafetyMarker::query()
            ->where('organization_id', $organization->getKey())
            ->where('medical_session_id', $session->getKey())
            ->orderBy('id')
            ->get();
        if ($markers->isNotEmpty()) {
            return $markers->map(fn (MedicalSessionSafetyMarker $marker): array => [
                'id' => (int) $marker->getKey(),
                'marker_key' => (string) $marker->marker_key,
                'marker_text' => (string) $this->encryptor->decryptField(
                    (int) $organization->getKey(),
                    $marker->marker_text,
                    (int) $marker->encryption_key_version,
                ),
                'source_session_id' => (int) $marker->medical_session_id,
            ])->values()->all();
        }

        $clientMarker = MedicalClientSafetyMarker::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $session->client_id)
            ->where('marker_key', MedicalTest18SafetyMarker::MARKER_KEY)
            ->where('is_active', true)
            ->first();
        if ($clientMarker === null) {
            return [];
        }

        return [[
            'id' => (int) $clientMarker->getKey(),
            'marker_key' => (string) $clientMarker->marker_key,
            'marker_text' => (string) $this->encryptor->decryptField(
                (int) $organization->getKey(),
                $clientMarker->marker_text,
                (int) $clientMarker->encryption_key_version,
            ),
            'source_session_id' => (int) $session->getKey(),
        ]];
    }

    /** @return array<string, mixed> */
    private function clientMarker(MedicalClientSafetyMarker $marker): array
    {
        $organizationId = (int) $marker->organization_id;
        $clearReason = $marker->clear_reason === null
            ? null
            : $this->encryptor->decryptField(
                $organizationId,
                $marker->clear_reason,
                (int) ($marker->clear_reason_key_version ?? $marker->encryption_key_version),
            );

        return [
            'id' => (int) $marker->getKey(),
            'marker_key' => (string) $marker->marker_key,
            'marker_text' => (string) $this->encryptor->decryptField(
                $organizationId,
                $marker->marker_text,
                (int) $marker->encryption_key_version,
            ),
            'is_active' => (bool) $marker->is_active,
            'source_session_id' => $marker->source_session_id === null ? null : (int) $marker->source_session_id,
            'activated_at' => $this->isoDate($marker->getAttribute('activated_at')),
            'activated_by' => $marker->activated_by === null ? null : (int) $marker->activated_by,
            'cleared_at' => $this->isoDate($marker->getAttribute('cleared_at')),
            'cleared_by' => $marker->cleared_by === null ? null : (int) $marker->cleared_by,
            'clear_reason' => $clearReason,
        ];
    }

    private function isoDate(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : null;
    }
}
