<?php

namespace App\Modules\AI\Application\Data;

use App\Modules\AI\Domain\Enums\AiEvaluationCheckCategory;

final readonly class AiEvaluationAssertionResult
{
    public function __construct(
        public string $type,
        public AiEvaluationCheckCategory $category,
        public bool $passed,
        public ?string $failureCode,
        public string $explanation,
        public ?string $path = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'category' => $this->category->value,
            'passed' => $this->passed,
            'failure_code' => $this->failureCode,
            'explanation' => $this->explanation,
            'path' => $this->path,
        ];
    }
}
