<?php

namespace App\Modules\AI\Application\Data;

use App\Modules\AI\Domain\Models\AiRun;

final readonly class ClinicalAiResultData
{
    /**
     * @param  array<string, mixed>|null  $outputPayload
     * @param  list<array<string, mixed>>  $attachmentProvenance
     */
    public function __construct(
        public AiRun $run,
        public ?array $outputPayload,
        public ?string $outputText,
        public array $attachmentProvenance,
    ) {}
}
