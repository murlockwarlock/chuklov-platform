<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Analytics\Domain\Enums\AnalyticsMetricCalculation;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricNullPolicy;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricZeroPolicy;

final class AnalyticsMetricCalculator
{
    public function calculate(
        AnalyticsMetricCalculation $calculation,
        ?float $a,
        ?float $b,
        AnalyticsMetricNullPolicy $nullPolicy,
        AnalyticsMetricZeroPolicy $zeroPolicy,
    ): ?float {
        if ($a === null) {
            if ($nullPolicy === AnalyticsMetricNullPolicy::DoNotCalculate) {
                return null;
            }
            $a = 0.0;
        }

        if ($calculation->requiresSecondValue() && $b === null) {
            if ($nullPolicy === AnalyticsMetricNullPolicy::DoNotCalculate) {
                return null;
            }
            $b = 0.0;
        }

        $value = match ($calculation) {
            AnalyticsMetricCalculation::Value => $a,
            AnalyticsMetricCalculation::Difference => $a - (float) $b,
            AnalyticsMetricCalculation::Sum => $a + (float) $b,
            AnalyticsMetricCalculation::Ratio => $this->divide($a, (float) $b, $zeroPolicy, false),
            AnalyticsMetricCalculation::RatioPercent => $this->divide($a, (float) $b, $zeroPolicy, true),
            AnalyticsMetricCalculation::ReductionPercent => $this->divide($a - (float) $b, $a, $zeroPolicy, true),
            AnalyticsMetricCalculation::ChangePercent => $this->divide($a - (float) $b, (float) $b, $zeroPolicy, true),
        };

        return $value === null || ! is_finite($value) ? null : $value;
    }

    private function divide(
        float $a,
        float $b,
        AnalyticsMetricZeroPolicy $zeroPolicy,
        bool $asPercent,
    ): ?float {
        if ($b === 0.0) {
            return $zeroPolicy === AnalyticsMetricZeroPolicy::ShowZero ? 0.0 : null;
        }

        $value = $a / $b * ($asPercent ? 100 : 1);

        return is_finite($value) ? $value : null;
    }
}
