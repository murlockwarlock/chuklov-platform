<?php

namespace App\Modules\Sessions\Application\DTOs;

final readonly class MedicalClinicalEvaluation
{
    public readonly int $resolvedCount;

    public readonly int $newCount;

    /**
     * @param  list<string>  $beforeViolationKeys
     * @param  list<string>  $afterViolationKeys
     * @param  list<int>  $causeIds
     * @param  list<string>  $warnings
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
        $before = array_fill_keys($this->beforeViolationKeys, true);
        $after = array_fill_keys($this->afterViolationKeys, true);
        $this->resolvedCount = count(array_diff_key($before, $after));
        $this->newCount = count(array_diff_key($after, $before));
    }
}
