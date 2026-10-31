<?php

namespace App\Modules\Analytics\Domain\Enums;

enum AnalyticsMetricComparison: string
{
    case None = 'none';
    case Metric = 'metric';
    case PreviousPeriod = 'previous_period';
    case Target = 'target';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Без сравнения',
            self::Metric => 'Другая выбранная метрика',
            self::PreviousPeriod => 'Предыдущий сопоставимый период',
            self::Target => 'Фиксированное целевое значение',
        };
    }
}
