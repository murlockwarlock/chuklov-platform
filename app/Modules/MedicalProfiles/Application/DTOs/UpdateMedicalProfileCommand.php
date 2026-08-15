<?php

namespace App\Modules\MedicalProfiles\Application\DTOs;

final readonly class UpdateMedicalProfileCommand
{
    public function __construct(
        public ?string $anamnesis = null,
        public ?string $complaintsGoals = null,
        public ?string $operationsInjuries = null,
        public ?string $medicines = null,
        public ?string $supplements = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            anamnesis: isset($data['anamnesis']) && is_string($data['anamnesis']) ? trim($data['anamnesis']) : null,
            complaintsGoals: isset($data['complaints_goals']) && is_string($data['complaints_goals']) ? trim($data['complaints_goals']) : null,
            operationsInjuries: isset($data['operations_injuries']) && is_string($data['operations_injuries']) ? trim($data['operations_injuries']) : null,
            medicines: isset($data['medicines']) && is_string($data['medicines']) ? trim($data['medicines']) : null,
            supplements: isset($data['supplements']) && is_string($data['supplements']) ? trim($data['supplements']) : null,
        );
    }
}
