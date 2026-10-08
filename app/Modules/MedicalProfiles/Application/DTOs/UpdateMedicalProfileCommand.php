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
        public ?string $expectedSnapshot = null,
        public ?string $complaints = null,
        public ?string $goals = null,
        public ?string $operations = null,
        public ?string $injuries = null,
        public ?bool $anamnesisProvided = null,
        public ?bool $complaintsGoalsProvided = null,
        public ?bool $operationsInjuriesProvided = null,
        public ?bool $medicinesProvided = null,
        public ?bool $supplementsProvided = null,
        public ?bool $complaintsProvided = null,
        public ?bool $goalsProvided = null,
        public ?bool $operationsProvided = null,
        public ?bool $injuriesProvided = null,
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
            expectedSnapshot: isset($data['expected_snapshot']) && is_string($data['expected_snapshot']) ? $data['expected_snapshot'] : null,
            complaints: isset($data['complaints']) && is_string($data['complaints']) ? trim($data['complaints']) : null,
            goals: isset($data['goals']) && is_string($data['goals']) ? trim($data['goals']) : null,
            operations: isset($data['operations']) && is_string($data['operations']) ? trim($data['operations']) : null,
            injuries: isset($data['injuries']) && is_string($data['injuries']) ? trim($data['injuries']) : null,
            anamnesisProvided: array_key_exists('anamnesis', $data),
            complaintsGoalsProvided: array_key_exists('complaints_goals', $data),
            operationsInjuriesProvided: array_key_exists('operations_injuries', $data),
            medicinesProvided: array_key_exists('medicines', $data),
            supplementsProvided: array_key_exists('supplements', $data),
            complaintsProvided: array_key_exists('complaints', $data),
            goalsProvided: array_key_exists('goals', $data),
            operationsProvided: array_key_exists('operations', $data),
            injuriesProvided: array_key_exists('injuries', $data),
        );
    }

    public function shouldUpdateAnamnesis(): bool
    {
        return $this->anamnesisProvided ?? true;
    }

    public function shouldUpdateComplaintsGoals(): bool
    {
        return $this->complaintsGoalsProvided ?? true;
    }

    public function shouldUpdateOperationsInjuries(): bool
    {
        return $this->operationsInjuriesProvided ?? true;
    }

    public function shouldUpdateMedicines(): bool
    {
        return $this->medicinesProvided ?? true;
    }

    public function shouldUpdateSupplements(): bool
    {
        return $this->supplementsProvided ?? true;
    }

    public function shouldUpdateComplaints(): bool
    {
        return $this->complaintsProvided ?? $this->complaints !== null;
    }

    public function shouldUpdateGoals(): bool
    {
        return $this->goalsProvided ?? $this->goals !== null;
    }

    public function shouldUpdateOperations(): bool
    {
        return $this->operationsProvided ?? $this->operations !== null;
    }

    public function shouldUpdateInjuries(): bool
    {
        return $this->injuriesProvided ?? $this->injuries !== null;
    }
}
