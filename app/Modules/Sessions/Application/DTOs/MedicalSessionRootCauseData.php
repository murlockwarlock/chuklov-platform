<?php

namespace App\Modules\Sessions\Application\DTOs;

final readonly class MedicalSessionRootCauseData
{
    public function __construct(
        public ?int $causeId,
        public ?string $causeName,
        public ?string $freeText,
        public bool $isPrimary = false,
        public string $status = 'новая',
        public ?string $region = null,
        /** @var list<int> */
        public array $influences = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'cause_id' => $this->causeId,
            'cause_name' => $this->causeName,
            'free_text' => $this->freeText,
            'is_primary' => $this->isPrimary,
            'status' => $this->status,
            'region' => $this->region,
            'influences' => $this->influences,
        ];
    }
}
