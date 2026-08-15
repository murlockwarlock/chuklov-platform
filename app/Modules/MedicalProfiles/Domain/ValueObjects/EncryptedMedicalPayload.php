<?php

namespace App\Modules\MedicalProfiles\Domain\ValueObjects;

final readonly class EncryptedMedicalPayload
{
    public function __construct(
        public ?string $encryptedAnamnesis,
        public ?string $encryptedComplaintsGoals,
        public ?string $encryptedOperationsInjuries,
        public ?string $encryptedMedicines,
        public ?string $encryptedSupplements,
        public int $keyVersion = 1,
    ) {}
}
