<?php

namespace App\Modules\AI\Application\Data;

use App\Modules\AI\Domain\Enums\AiErrorCategory;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\ValueObjects\AiTokenUsage;

final readonly class AiRunResult
{
    /**
     * @param  array<string, mixed>|null  $outputPayload
     */
    public function __construct(
        public int $runId,
        public AiRunStatus $status,
        public ?string $outputText = null,
        public ?array $outputPayload = null,
        public AiTokenUsage $tokenUsage = new AiTokenUsage,
        public int $latencyMs = 0,
        public ?int $settledEstimatedCostMinorUnits = null,
        public ?int $providerCostMinorUnits = null,
        public string $costCurrency = 'USD',
        public ?AiErrorCategory $errorCategory = null,
        public ?string $errorMessageSanitized = null,
    ) {}

    public function isSuccess(): bool
    {
        return $this->status === AiRunStatus::Succeeded;
    }
}
