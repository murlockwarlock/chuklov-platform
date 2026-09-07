<?php

namespace App\Modules\AI\Application\Data;

final readonly class AiRunProtectedTraceData
{
    /**
     * @param  array<string, mixed>|null  $outputPayload
     * @param  list<array<string, mixed>>  $inputReferences
     * @param  array<string, mixed>  $contextProvenance
     * @param  list<array<string, mixed>>  $ragReferences
     * @param  array<string, mixed>  $model
     */
    public function __construct(
        public int $aiRunId,
        public int $encryptionKeyVersion,
        public ?string $systemPrompt,
        public ?string $userPrompt,
        public ?string $outputText,
        public ?array $outputPayload,
        public ?string $humanReviewNotes,
        public ?string $humanEditedOutput,
        public ?string $promptName = null,
        public ?int $promptVersion = null,
        public array $inputReferences = [],
        public array $contextProvenance = [],
        public array $ragReferences = [],
        public array $model = [],
        public ?string $sourcePrompt = null,
        public ?string $platformSafetyGuardrails = null,
    ) {}
}
