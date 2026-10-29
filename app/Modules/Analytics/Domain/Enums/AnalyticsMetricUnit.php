<?php

namespace App\Modules\Analytics\Domain\Enums;

enum AnalyticsMetricUnit: string
{
    case Percent = 'percent';
    case Money = 'money';
    case Number = 'number';
    case Score = 'score';

    public function label(): string
    {
        return match ($this) {
            self::Percent => '%',
            self::Money => 'Деньги',
            self::Number => 'Число',
            self::Score => 'Баллы',
        };
    }
}
