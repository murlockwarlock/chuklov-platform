<?php

namespace App\Modules\AI\Domain\ValueObjects;

final readonly class AiRunExecutionContext
{
    public function __construct(
        public int $organizationId,
        public int $aiRunId,
        public string $workerLeaseToken,
    ) {}
}
