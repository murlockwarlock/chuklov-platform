<?php

namespace App\Modules\AI\Application\Data;

final readonly class AiRunProtectedTraceData
{
    /**
     * @param  array<string, mixed>|null  $outputPayload
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
    ) {}
}
