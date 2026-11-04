<?php

namespace App\Modules\Analytics\Domain\Enums;

enum AnalyticsMetricSourceKind: string
{
    case Money = 'money';
    case Count = 'count';
    case Score = 'score';
    case Number = 'number';
}
