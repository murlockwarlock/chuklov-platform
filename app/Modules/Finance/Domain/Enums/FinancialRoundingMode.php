<?php

namespace App\Modules\Finance\Domain\Enums;

use Brick\Math\RoundingMode;
use InvalidArgumentException;

enum FinancialRoundingMode: string
{
    case Down = 'down';
    case HalfEven = 'half_even';
    case HalfUp = 'half_up';

    public function brick(): RoundingMode
    {
        return match ($this) {
            self::Down => RoundingMode::Down,
            self::HalfEven => RoundingMode::HalfEven,
            self::HalfUp => RoundingMode::HalfUp,
        };
    }

    public static function fromMixed(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $mode = self::tryFrom((string) $value);

        if ($mode === null) {
            throw new InvalidArgumentException('The financial rounding mode is invalid.');
        }

        return $mode;
    }
}
