<?php

namespace App\Modules\Sessions\Application\DTOs;

final readonly class MedicalClinicalEvaluation
{
    public readonly int $resolvedCount;

    public readonly int $newCount;

    /**
     * @param list<string> $beforeViolationKeys
     * @param list<string> $afterViolationKeys
     * @param list<int> $causeIds
     * @param list<string> $warnings
     */
    public function __construct(
        public int $beforeCount = 0,
        public int $afterCount = 0,
        public array $beforeViolationKeys = [],
        public array $afterViolationKeys = [],
        public array $causeIds = [],
        public array $warnings = [],
        public bool $failClosed = false,
    ) {
        $this->resolvedCount = max(0, $this->beforeCount - $this->afterCount);
        $this->newCount = max(0, $this->afterCount - $this->beforeCount);
    }
}
