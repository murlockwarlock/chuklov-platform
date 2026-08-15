<?php

namespace App\Modules\MedicalProfiles\Domain\Contracts;

use App\Modules\MedicalProfiles\Application\DTOs\MedicalProfileData;
use App\Modules\MedicalProfiles\Domain\ValueObjects\EncryptedMedicalPayload;

interface MedicalEncryptorInterface
{
    public function encryptField(int $organizationId, ?string $plaintext, ?int $keyVersion = null): ?string;

    public function decryptField(int $organizationId, ?string $ciphertext, int $keyVersion): ?string;

    public function encryptProfile(int $organizationId, MedicalProfileData $data, ?int $keyVersion = null): EncryptedMedicalPayload;

    public function decryptProfile(int $organizationId, int $keyVersion, EncryptedMedicalPayload $payload): MedicalProfileData;
}
