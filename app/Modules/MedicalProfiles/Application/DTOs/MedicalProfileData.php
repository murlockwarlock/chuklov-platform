<?php

namespace App\Modules\MedicalProfiles\Application\DTOs;

use DateTimeInterface;

final readonly class MedicalProfileData
{
    public function __construct(
        public ?string $anamnesis,
        public ?string $complaintsGoals,
        public ?string $operationsInjuries,
        public ?string $medicines,
        public ?string $supplements,
        public int $encryptionKeyVersion = 1,
        public ?DateTimeInterface $updatedAt = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'anamnesis' => $this->anamnesis,
            'complaints_goals' => $this->complaintsGoals,
            'operations_injuries' => $this->operationsInjuries,
            'medicines' => $this->medicines,
            'supplements' => $this->supplements,
            'encryption_key_version' => $this->encryptionKeyVersion,
            'updated_at' => $this->updatedAt?->format(DateTimeInterface::ATOM),
        ];
    }
}
