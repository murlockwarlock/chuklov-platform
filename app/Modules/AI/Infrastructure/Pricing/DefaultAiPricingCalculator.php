<?php

namespace App\Modules\AI\Infrastructure\Pricing;

use App\Modules\AI\Domain\Contracts\AiPricingCalculatorInterface;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;

class DefaultAiPricingCalculator implements AiPricingCalculatorInterface
{
    public function calculateEstimatedCost(AiPricingSnapshot $pricing, int $promptTokens, int $completionTokens): int
    {
        return $pricing->calculateCostMinorUnits($promptTokens, $completionTokens);
    }
}
