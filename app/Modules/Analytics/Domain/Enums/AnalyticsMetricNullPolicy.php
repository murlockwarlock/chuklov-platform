<?php

namespace App\Modules\Analytics\Domain\Enums;

enum AnalyticsMetricNullPolicy: string
{
    case DoNotCalculate = 'do_not_calculate';
    case TreatAsZero = 'treat_as_zero';

    public function label(): string
    {
        return match ($this) {
            self::DoNotCalculate => 'Не рассчитывать',
            self::TreatAsZero => 'Считать как 0',
        };
    }
}
