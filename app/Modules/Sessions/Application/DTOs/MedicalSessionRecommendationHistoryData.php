<?php

namespace App\Modules\Sessions\Application\DTOs;

use DateTimeInterface;

final readonly class MedicalSessionRecommendationHistoryData
{
    /** @param list<MedicalSessionRecommendationDeliveryData> $deliveries */
    public function __construct(
        public int $sessionId,
        public int $clientId,
        public string $draft,
        public int $revision,
        public ?DateTimeInterface $occurredAt,
        public array $deliveries,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'client_id' => $this->clientId,
            'draft' => $this->draft,
            'revision' => $this->revision,
            'occurred_at' => $this->occurredAt?->format(DateTimeInterface::ATOM),
            'deliveries' => array_map(
                static fn (MedicalSessionRecommendationDeliveryData $delivery): array => $delivery->toArray(),
                $this->deliveries,
            ),
        ];
    }
}
