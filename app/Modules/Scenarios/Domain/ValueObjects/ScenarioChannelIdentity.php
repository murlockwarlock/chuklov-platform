<?php

namespace App\Modules\Scenarios\Domain\ValueObjects;

final readonly class ScenarioChannelIdentity
{
    public function __construct(
        public string $channel,
        public string $externalId,
    ) {}
}
