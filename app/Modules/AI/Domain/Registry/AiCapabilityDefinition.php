<?php

namespace App\Modules\AI\Domain\Registry;

use App\Modules\AI\Domain\Enums\AiCapability;

final readonly class AiCapabilityDefinition
{
    /**
     * @param  list<string>  $allowedInputReferenceTypes
     * @param  list<string>  $allowedTools
     * @param  array<string, mixed>|null  $defaultOutputSchema
     */
    public function __construct(
        public AiCapability $capability,
        public string $displayName,
        public string $description,
        public array $allowedInputReferenceTypes,
        public bool $supportsRag,
        public array $allowedTools,
        public int $defaultTimeoutSeconds,
        public int $maxTimeoutSeconds,
        public int $defaultMaxTokens,
        public bool $requiresHumanReview,
        public ?array $defaultOutputSchema = null,
    ) {}
}
