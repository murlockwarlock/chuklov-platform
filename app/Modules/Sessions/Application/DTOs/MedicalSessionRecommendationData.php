<?php

namespace App\Modules\Sessions\Application\DTOs;

use DateTimeInterface;

final readonly class MedicalSessionRecommendationData
{
    public function __construct(
        public int $sessionId,
        public int $organizationId,
        public int $clientId,
        public string $draft,
        public int $revision,
        public ?string $generated,
        public ?DateTimeInterface $updatedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'organization_id' => $this->organizationId,
            'client_id' => $this->clientId,
            'draft' => $this->draft,
            'revision' => $this->revision,
            'generated' => $this->generated,
            'updated_at' => $this->updatedAt?->format(DateTimeInterface::ATOM),
        ];
    }
}
