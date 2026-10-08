<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
use App\Modules\MedicalProfiles\Domain\ValueObjects\EncryptedMedicalPayload;

final readonly class GetClientMedicalProfile
{
    public function __construct(
        private ClientPortalContext $clientContext,
        private MedicalEncryptorInterface $encryptor,
    ) {}

    /** @return array{available: bool, updatedAt: string|null, anamnesis: string|null, complaintsGoals: string|null, operationsInjuries: string|null, medicines: string|null, supplements: string|null} */
    public function handle(): array
    {
        $client = $this->clientContext->client();
        $profile = MedicalProfile::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->first();

        if ($profile === null) {
            return [
                'available' => false,
                'updatedAt' => null,
                'anamnesis' => null,
                'complaintsGoals' => null,
                'operationsInjuries' => null,
                'medicines' => null,
                'supplements' => null,
            ];
        }

        try {
            $data = $this->encryptor->decryptProfile(
                (int) $client->organization_id,
                (int) $profile->encryption_key_version,
                new EncryptedMedicalPayload(
                    encryptedAnamnesis: $profile->anamnesis,
                    encryptedComplaintsGoals: $profile->complaints_goals,
                    encryptedOperationsInjuries: $profile->operations_injuries,
                    encryptedMedicines: $profile->medicines,
                    encryptedSupplements: $profile->supplements,
                    keyVersion: (int) $profile->encryption_key_version,
                ),
            );
        } catch (\Throwable) {
            return [
                'available' => false,
                'updatedAt' => null,
                'anamnesis' => null,
                'complaintsGoals' => null,
                'operationsInjuries' => null,
                'medicines' => null,
                'supplements' => null,
            ];
        }

        return [
            'available' => true,
            'updatedAt' => $profile->updated_at?->toIso8601String(),
            'anamnesis' => $data->anamnesis,
            'complaintsGoals' => $data->complaintsGoals,
            'operationsInjuries' => $data->operationsInjuries,
            'medicines' => $data->medicines,
            'supplements' => $data->supplements,
        ];
    }
}
