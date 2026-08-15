<?php

namespace App\Modules\MedicalProfiles\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\DTOs\MedicalProfileData;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
use App\Modules\MedicalProfiles\Domain\ValueObjects\EncryptedMedicalPayload;

final readonly class GetMedicalProfile
{
    public function __construct(
        private MedicalProfileAuthorization $authorization,
        private MedicalEncryptorInterface $encryptor,
    ) {}

    public function handle(User $actor, Client $client): ?MedicalProfileData
    {
        $organization = $this->authorization->authorizeView($actor, $client);
        $orgId = (int) $organization->getKey();

        $profile = MedicalProfile::query()
            ->where('organization_id', $orgId)
            ->where('client_id', $client->getKey())
            ->first();

        if ($profile === null) {
            return null;
        }

        $payload = new EncryptedMedicalPayload(
            encryptedAnamnesis: $profile->anamnesis,
            encryptedComplaintsGoals: $profile->complaints_goals,
            encryptedOperationsInjuries: $profile->operations_injuries,
            encryptedMedicines: $profile->medicines,
            encryptedSupplements: $profile->supplements,
            keyVersion: $profile->encryption_key_version,
        );

        $data = $this->encryptor->decryptProfile($orgId, $profile->encryption_key_version, $payload);

        return new MedicalProfileData(
            anamnesis: $data->anamnesis,
            complaintsGoals: $data->complaintsGoals,
            operationsInjuries: $data->operationsInjuries,
            medicines: $data->medicines,
            supplements: $data->supplements,
            encryptionKeyVersion: $profile->encryption_key_version,
            updatedAt: $profile->updated_at,
        );
    }
}
