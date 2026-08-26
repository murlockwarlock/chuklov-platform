<?php

namespace App\Modules\Integration\Domain\ValueObjects;

use App\Modules\Integration\Domain\Enums\IntegrationEventType;
use DateTimeInterface;

final readonly class IntegrationEventData
{
    /**
     * @param  array<string, scalar|null>  $payload
     */
    public function __construct(
        public IntegrationEventType $eventType,
        public string $aggregateType,
        public int $aggregateId,
        public string $idempotencyKey,
        public array $payload,
        public DateTimeInterface $occurredAt,
    ) {}
}
