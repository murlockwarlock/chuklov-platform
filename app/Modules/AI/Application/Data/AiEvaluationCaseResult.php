<?php

namespace App\Modules\AI\Application\Data;

use App\Modules\AI\Domain\Enums\AiEvaluationCaseStatus;

final readonly class AiEvaluationCaseResult
{
    /**
     * @param  list<array<string, mixed>>  $assertions
     * @param  array<string, mixed>  $execution
     * @param  array<string, mixed>  $rag
     */
    public function __construct(
        public int $caseId,
        public string $caseName,
        public int $aiRunId,
        public AiEvaluationCaseStatus $status,
        public bool $passed,
        public ?string $failureCategory,
        public ?string $failureCode,
        public string $failureExplanation,
        public array $assertions = [],
        public array $execution = [],
        public array $rag = [],
        public ?int $modelReleaseId = null,
        public ?string $actualProvider = null,
        public ?string $actualModel = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'case_id' => $this->caseId,
            'case_name' => $this->caseName,
            'ai_run_id' => $this->aiRunId,
            'status' => $this->status->value,
            'passed' => $this->passed,
            'failure_category' => $this->failureCategory,
            'failure_code' => $this->failureCode,
            'failure_explanation' => $this->failureExplanation,
            'assertions' => $this->assertions,
            'execution' => $this->execution,
            'rag' => $this->rag,
            'model_release_id' => $this->modelReleaseId,
            'actual_provider' => $this->actualProvider,
            'actual_model' => $this->actualModel,
        ];
    }
}
