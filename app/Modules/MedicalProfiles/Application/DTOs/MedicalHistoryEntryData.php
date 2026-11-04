<?php

namespace App\Modules\MedicalProfiles\Application\DTOs;

final readonly class MedicalHistoryEntryData
{
    public function __construct(
        public int $id,
        public string $lineageKey,
        public string $type,
        public int $year,
        public string $description,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'lineage_key' => $this->lineageKey,
            'type' => $this->type,
            'year' => $this->year,
            'description' => $this->description,
        ];
    }
}
