<?php

namespace App\Modules\Scenarios\Domain\Enums;

use InvalidArgumentException;

enum ScenarioDelayUnit: string
{
    case Minutes = 'minutes';
    case Hours = 'hours';
    case Days = 'days';

    public function toSeconds(int $value): int
    {
        $multiplier = match ($this) {
            self::Minutes => 60,
            self::Hours => 3600,
            self::Days => 86400,
        };

        if ($value < 0 || $value > intdiv(PHP_INT_MAX, $multiplier)) {
            throw new InvalidArgumentException('The scenario delay is outside the supported range.');
        }

        return $value * $multiplier;
    }
}
