<?php

namespace App\Modules\Sessions\Application;

use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Sessions\Domain\Models\MedicalAnthropometricMeasurement;

final readonly class MedicalAnthropometryReader
{
    public function __construct(private MedicalEncryptorInterface $encryptor) {}

    /** @return array<string, mixed> */
    public function values(MedicalAnthropometricMeasurement $measurement): array
    {
        $decoded = json_decode(
            $this->encryptor->decryptField(
                (int) $measurement->organization_id,
                $measurement->payload,
                (int) $measurement->encryption_key_version,
            ) ?? '',
            true,
        );

        return is_array($decoded) ? $decoded : [];
    }
}
