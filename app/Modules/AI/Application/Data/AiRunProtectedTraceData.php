<?php

namespace App\Modules\AI\Application\Data;

final readonly class AiRunProtectedTraceData
{
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
