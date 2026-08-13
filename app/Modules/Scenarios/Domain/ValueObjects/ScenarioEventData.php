<?php

namespace App\Modules\Scenarios\Domain\ValueObjects;

use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use Carbon\CarbonImmutable;

final readonly class ScenarioEventData
{
    /** @param array<string, scalar|null> $payload */
    public function __construct(
        public ScenarioEventType $eventType,
        public string $aggregateType,
        public string $aggregateId,
        public CarbonImmutable $occurredAt,
        public array $payload,
        public string $idempotencyKey,
        public ?string $correlationId,
        public ?string $causationId,
    ) {}

}
