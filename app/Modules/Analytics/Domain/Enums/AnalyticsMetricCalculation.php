<?php

namespace App\Modules\Analytics\Domain\Enums;

enum AnalyticsMetricCalculation: string
{
    case Value = 'value';
    case Difference = 'difference';
    case Sum = 'sum';
    case Ratio = 'ratio';
    case RatioPercent = 'ratio_percent';
    case ReductionPercent = 'reduction_percent';
    case ChangePercent = 'change_percent';

    public function label(): string
    {
        return match ($this) {
            self::Value => 'Значение A',
            self::Difference => 'A − B',
            self::Sum => 'A + B',
            self::Ratio => 'A ÷ B',
            self::RatioPercent => 'A ÷ B × 100',
            self::ReductionPercent => '(A − B) ÷ A × 100',
            self::ChangePercent => '(A − B) ÷ B × 100',
        };
    }

    public function requiresSecondValue(): bool
    {
        return $this !== self::Value;
    }

    public function producesPercent(): bool
    {
        return in_array($this, [
            self::RatioPercent,
            self::ReductionPercent,
            self::ChangePercent,
        ], true);
    }
}
