<?php

namespace App\Modules\Sessions\Application\DTOs;

final readonly class MedicalSessionEffectivenessData
{
    public function __construct(
        public ?float $averagePainReductionPercentage,
        public int $fullyResolvedComplaints,
        public int $violationsBefore,
        public int $violationsAfter,
        public int $resolvedTests,
        public int $newViolations,
        public int $testsBecameZero,
        public int $uniqueCauseCount,
    ) {}

    /** @return array<string, int|float|null> */
    public function toArray(): array
    {
        return [
            'average_pain_reduction_percentage' => $this->averagePainReductionPercentage,
            'fully_resolved_complaints' => $this->fullyResolvedComplaints,
            'violations_before' => $this->violationsBefore,
            'violations_after' => $this->violationsAfter,
            'resolved_tests' => $this->resolvedTests,
            'new_violations' => $this->newViolations,
            'tests_became_zero' => $this->testsBecameZero,
            'unique_cause_count' => $this->uniqueCauseCount,
        ];
    }
}
