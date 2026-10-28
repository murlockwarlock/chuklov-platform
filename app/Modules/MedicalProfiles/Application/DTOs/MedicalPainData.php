<?php

namespace App\Modules\MedicalProfiles\Application\DTOs;

final readonly class MedicalPainData
{
    /** @param list<string> $timeOfDay */
    public function __construct(
        public int $id,
        public string $lineageKey,
        public string $name,
        public string $frequencyMode,
        public ?int $frequencyCount,
        public ?string $frequencyPeriodUnit,
        public array $timeOfDay,
        public ?int $score = null,
        public ?int $beforeScore = null,
        public ?int $afterScore = null,
        public ?int $previousAfterScore = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'lineage_key' => $this->lineageKey,
            'name' => $this->name,
            'frequency_mode' => $this->frequencyMode,
            'frequency_count' => $this->frequencyCount,
            'frequency_period_unit' => $this->frequencyPeriodUnit,
            'time_of_day' => $this->timeOfDay,
            'score' => $this->score,
            'before_score' => $this->beforeScore,
            'after_score' => $this->afterScore,
            'previous_after_score' => $this->previousAfterScore,
        ];
    }
}
