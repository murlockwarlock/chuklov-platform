<?php

namespace App\Modules\AI\Infrastructure\Pricing;

use App\Modules\AI\Domain\Contracts\AiPricingCalculatorInterface;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;

class DefaultAiPricingCalculator implements AiPricingCalculatorInterface
{
    public function calculateEstimatedCost(
        AiPricingSnapshot $pricing,
        int $promptTokens,
        int $completionTokens,
        int $cacheReadInputTokens = 0,
        int $cacheWriteInputTokens = 0,
        int $reasoningTokens = 0,
        int $providerRequests = 0,
    ): int {
        return $pricing->calculateCostMinorUnits(
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            cacheReadInputTokens: $cacheReadInputTokens,
            cacheWriteInputTokens: $cacheWriteInputTokens,
            reasoningTokens: $reasoningTokens,
            providerRequests: $providerRequests,
        );
    }
}
