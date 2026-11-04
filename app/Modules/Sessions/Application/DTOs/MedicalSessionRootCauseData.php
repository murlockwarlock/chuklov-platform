<?php

namespace App\Modules\Sessions\Application\DTOs;

final readonly class MedicalSessionRootCauseData
{
    public function __construct(
        public ?int $causeId,
        public ?string $causeName,
        public ?string $freeText,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'cause_id' => $this->causeId,
            'cause_name' => $this->causeName,
            'free_text' => $this->freeText,
        ];
    }
}
