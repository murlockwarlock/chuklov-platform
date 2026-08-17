<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;

interface AiPricingCalculatorInterface
{
    public function calculateEstimatedCost(
        AiPricingSnapshot $pricing,
        int $promptTokens,
        int $completionTokens,
        int $cacheReadInputTokens = 0,
        int $cacheWriteInputTokens = 0,
        int $reasoningTokens = 0,
        int $providerRequests = 0,
    ): int;
}
