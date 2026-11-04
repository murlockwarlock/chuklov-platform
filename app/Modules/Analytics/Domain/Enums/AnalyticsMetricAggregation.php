<?php

namespace App\Modules\Analytics\Domain\Enums;

enum AnalyticsMetricAggregation: string
{
    case SourceValue = 'source_value';
    case Sum = 'sum';
    case Average = 'average';
    case Count = 'count';

    public function label(): string
    {
        return match ($this) {
            self::SourceValue => 'Значение источника',
            self::Sum => 'Сумма',
            self::Average => 'Среднее',
            self::Count => 'Количество',
        };
    }
}
