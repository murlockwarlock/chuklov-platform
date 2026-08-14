<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioCondition;
use InvalidArgumentException;

final class BooleanScenarioCondition
{
    /** @return list<bool> */
    public static function values(ScenarioCondition $condition): array
    {
        if ($condition->operator === ScenarioConditionOperator::Exists) {
            return [];
        }

        $values = $condition->operator === ScenarioConditionOperator::In
            ? $condition->value
            : [$condition->value];

        if (! is_array($values)) {
            throw new InvalidArgumentException('The boolean condition value is invalid.');
        }

        $normalized = [];

        foreach ($values as $value) {
            $normalized[] = self::value($value);
        }

        return $normalized;
    }

    public static function value(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
            return strtolower($value) === 'true';
        }

        throw new InvalidArgumentException('The boolean condition value is invalid.');
    }
}
