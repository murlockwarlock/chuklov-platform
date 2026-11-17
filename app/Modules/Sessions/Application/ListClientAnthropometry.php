<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Sessions\Domain\Models\MedicalAnthropometricMeasurement;
use Illuminate\Support\Collection;

final readonly class ListClientAnthropometry
{
    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private MedicalAnthropometryReader $reader,
    ) {}

    /** @return Collection<int, array{id: int, measured_at: mixed, values: array<string, mixed>}> */
    public function handle(User $actor, Client $client): Collection
    {
        $organization = $this->authorization->authorizeViewClient($actor, $client);
        $orgId = (int) $organization->getKey();

        return MedicalAnthropometricMeasurement::query()
            ->where('organization_id', $orgId)
            ->where('client_id', $client->getKey())
            ->orderByDesc('measured_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (MedicalAnthropometricMeasurement $measurement): array {
                return [
                    'id' => (int) $measurement->getKey(),
                    'measured_at' => $measurement->measured_at,
                    'values' => $this->reader->values($measurement),
                ];
            });
    }
}
