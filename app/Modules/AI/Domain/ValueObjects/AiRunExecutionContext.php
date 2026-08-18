<?php

namespace App\Modules\AI\Domain\ValueObjects;

use Carbon\CarbonInterface;

final readonly class AiRunExecutionContext
{
    public function __construct(
        public int $organizationId,
        public int $aiRunId,
        public string $workerLeaseToken,
        public ?CarbonInterface $executionDeadlineAt = null,
    ) {}
}
