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
        public ?string $complaints = null,
        public ?string $goals = null,
        public ?string $operations = null,
        public ?string $injuries = null,
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
            'complaints' => $this->complaints,
            'goals' => $this->goals,
            'operations' => $this->operations,
            'injuries' => $this->injuries,
            'encryption_key_version' => $this->encryptionKeyVersion,
            'updated_at' => $this->updatedAt?->format(DateTimeInterface::ATOM),
        ];
    }

    public function hasSplitFields(): bool
    {
        foreach ([
            $this->complaints,
            $this->goals,
            $this->operations,
            $this->injuries,
        ] as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    public function complaintsGoalsForCompatibility(): ?string
    {
        if (! $this->hasSplitFields()) {
            return $this->complaintsGoals;
        }

        return $this->joinFields([
            'Жалобы' => $this->complaints,
            'Цели' => $this->goals,
        ]);
    }

    public function operationsInjuriesForCompatibility(): ?string
    {
        if (! $this->hasSplitFields()) {
            return $this->operationsInjuries;
        }

        return $this->joinFields([
            'Операции' => $this->operations,
            'Травмы' => $this->injuries,
        ]);
    }

    /** @param array<string, ?string> $fields */
    private function joinFields(array $fields): ?string
    {
        $parts = [];

        foreach ($fields as $label => $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                $parts[] = $label.":\n".$value;
            }
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }
}
