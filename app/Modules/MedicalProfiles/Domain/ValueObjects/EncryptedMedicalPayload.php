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
        public ?string $encryptedComplaints = null,
        public ?string $encryptedGoals = null,
        public ?string $encryptedOperations = null,
        public ?string $encryptedInjuries = null,
    ) {}
}
