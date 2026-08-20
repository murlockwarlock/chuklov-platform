<?php

namespace App\Modules\Finance\Application;

use Illuminate\Contracts\Database\Query\ConditionExpression;
use Illuminate\Database\Grammar;

final class FinanceSqlCondition implements ConditionExpression
{
    public function __construct(private readonly string $value) {}

    public function getValue(Grammar $grammar): string
    {
        return $this->value;
    }
}
