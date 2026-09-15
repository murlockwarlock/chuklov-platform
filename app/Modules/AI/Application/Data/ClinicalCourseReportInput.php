<?php

namespace App\Modules\AI\Application\Data;

use App\Modules\AI\Domain\ValueObjects\AiInputReference;

final readonly class ClinicalCourseReportInput
{
    /**
     * @param  array<string, mixed>  $inputVariables
     * @param  list<AiInputReference>  $inputReferences
     */
    public function __construct(
        public array $inputVariables,
        public array $inputReferences,
        public string $sourceDigest,
    ) {}
}
