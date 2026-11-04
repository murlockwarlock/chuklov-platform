<?php

namespace App\Modules\Analytics\Domain\Enums;

enum AnalyticsMetricZeroPolicy: string
{
    case DoNotCalculate = 'do_not_calculate';
    case ShowZero = 'show_zero';

    public function label(): string
    {
        return match ($this) {
            self::DoNotCalculate => 'Не рассчитывать',
            self::ShowZero => 'Показывать 0',
        };
    }
}
