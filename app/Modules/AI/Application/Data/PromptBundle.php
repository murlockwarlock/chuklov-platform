<?php

namespace App\Modules\AI\Application\Data;

use App\Modules\AI\Domain\Enums\AiCapability;

final readonly class PromptBundle
{
    /**
     * @param  array<string, mixed>  $variablesSchema
     * @param  array<string, mixed>  $parameterConfig
     * @param  array<string, mixed>  $contextPolicy
     * @param  array<string, mixed>|null  $outputSchema
     * @param  list<string>  $allowedTools
     */
    public function __construct(
        public string $promptKey,
        public string $name,
        public ?string $description,
        public AiCapability $capability,
        public int $version,
        public string $systemPrompt,
        public string $userPromptTemplate,
        public array $variablesSchema,
        public array $parameterConfig,
        public array $contextPolicy,
        public ?array $outputSchema = null,
        public array $allowedTools = [],
        public ?string $changeNotes = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            promptKey: (string) ($data['prompt_key'] ?? $data['key'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            description: isset($data['description']) ? (string) $data['description'] : null,
            capability: $data['capability'] instanceof AiCapability ? $data['capability'] : AiCapability::from((string) $data['capability']),
            version: (int) ($data['version'] ?? 1),
            systemPrompt: (string) ($data['system_prompt'] ?? ''),
            userPromptTemplate: (string) ($data['user_prompt_template'] ?? ''),
            variablesSchema: (array) ($data['variables_schema'] ?? []),
            parameterConfig: (array) ($data['parameter_config'] ?? []),
            contextPolicy: (array) ($data['context_policy'] ?? []),
            outputSchema: isset($data['output_schema']) ? (array) $data['output_schema'] : null,
            allowedTools: array_values(array_map('strval', (array) ($data['allowed_tools'] ?? []))),
            changeNotes: isset($data['change_notes']) ? (string) $data['change_notes'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'prompt_key' => $this->promptKey,
            'name' => $this->name,
            'description' => $this->description,
            'capability' => $this->capability->value,
            'version' => $this->version,
            'system_prompt' => $this->systemPrompt,
            'user_prompt_template' => $this->userPromptTemplate,
            'variables_schema' => $this->variablesSchema,
            'parameter_config' => $this->parameterConfig,
            'context_policy' => $this->contextPolicy,
            'output_schema' => $this->outputSchema,
            'allowed_tools' => $this->allowedTools,
            'change_notes' => $this->changeNotes,
        ];
    }
}
