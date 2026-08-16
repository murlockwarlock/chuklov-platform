<?php

namespace App\Modules\AI\Domain\ValueObjects;

use App\Modules\AI\Domain\Enums\AiCapability;

final readonly class AgentRelease
{
    /**
     * @param  list<string>  $allowedTools
     * @param  array<string, mixed>|null  $outputSchema
     */
    public function __construct(
        public AiCapability $capability,
        public int $promptVersionId,
        public int $modelReleaseId,
        public AiContextPolicy $contextPolicy,
        public array $allowedTools = [],
        public ?array $outputSchema = null,
        public bool $humanReviewRequired = false,
        public int $timeoutSeconds = 60,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            capability: $data['capability'] instanceof AiCapability ? $data['capability'] : AiCapability::from((string) $data['capability']),
            promptVersionId: (int) $data['prompt_version_id'],
            modelReleaseId: (int) $data['model_release_id'],
            contextPolicy: $data['context_policy'] instanceof AiContextPolicy ? $data['context_policy'] : AiContextPolicy::fromArray((array) ($data['context_policy'] ?? [])),
            allowedTools: array_values(array_map('strval', (array) ($data['allowed_tools'] ?? []))),
            outputSchema: isset($data['output_schema']) ? (array) $data['output_schema'] : null,
            humanReviewRequired: (bool) ($data['human_review_required'] ?? false),
            timeoutSeconds: (int) ($data['timeout_seconds'] ?? 60),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'capability' => $this->capability->value,
            'prompt_version_id' => $this->promptVersionId,
            'model_release_id' => $this->modelReleaseId,
            'context_policy' => $this->contextPolicy->toArray(),
            'allowed_tools' => $this->allowedTools,
            'output_schema' => $this->outputSchema,
            'human_review_required' => $this->humanReviewRequired,
            'timeout_seconds' => $this->timeoutSeconds,
        ];
    }
}
