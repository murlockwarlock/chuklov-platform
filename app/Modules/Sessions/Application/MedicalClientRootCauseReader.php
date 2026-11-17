<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Sessions\Domain\Models\MedicalClientRootCause;

final readonly class MedicalClientRootCauseReader
{
    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private MedicalEncryptorInterface $encryptor,
    ) {}

    /** @return list<array<string, mixed>> */
    public function forClient(User $actor, Client $client): array
    {
        $organization = $this->authorization->authorizeViewClient($actor, $client);

        return MedicalClientRootCause::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->with('cause')
            ->orderByRaw("case status when 'новая' then 0 when 'в работе' then 1 else 2 end")
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (MedicalClientRootCause $root): array => [
                'id' => (int) $root->getKey(),
                'cause_id' => $root->medical_cause_definition_id === null ? null : (int) $root->medical_cause_definition_id,
                'cause_name' => $root->cause === null ? null : MedicalClinicalCatalog::causeDisplayName((string) $root->cause->name),
                'status' => (string) $root->status,
                'region' => $this->decrypt($organization->getKey(), $root->region, $root->region_key_version),
                'free_text' => $this->decrypt($organization->getKey(), $root->free_text, $root->free_text_key_version),
                'relations' => $this->relations($organization->getKey(), $root),
                'last_seen_at' => $root->last_seen_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function decrypt(int $organizationId, mixed $value, mixed $version): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $this->encryptor->decryptField($organizationId, $value, (int) ($version ?: 1));
    }

    /** @return list<int> */
    private function relations(int $organizationId, MedicalClientRootCause $root): array
    {
        $value = $this->decrypt($organizationId, $root->relations, $root->relations_key_version);
        if ($value === null) {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) && is_array($decoded['cause_ids'] ?? null)
            ? array_values(array_unique(array_filter(array_map('intval', $decoded['cause_ids']), static fn (int $id): bool => $id > 0)))
            : [];
    }
}
