<?php

namespace App\Modules\AI\Domain\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use InvalidArgumentException;

final readonly class AiMoney
{
    private const SCALE = 2;

    public const int RATE_SCALE = 1_000_000;

    public static function minorUnitsFromDecimal(mixed $value): int
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match('/^(0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $value) !== 1) {
            throw new InvalidArgumentException('Enter a non-negative monetary value with a valid decimal format.');
        }

        try {
            $minorUnits = BigDecimal::of($value)->toScale(self::SCALE)->getUnscaledValue();
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('The monetary value has more precision than the billing format supports.', previous: $exception);
        }

        return self::toInt($minorUnits, 'The monetary value is outside the supported range.');
    }

    public static function canonicalMinorUnits(mixed $value, string $field = 'monetary value'): int
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw new InvalidArgumentException("{$field} cannot be negative.");
            }

            return $value;
        }

        if (! is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/', $value) !== 1) {
            throw new InvalidArgumentException("{$field} must be a non-negative integer in minor units.");
        }

        return self::toInt(BigInteger::of($value), "{$field} is outside the supported range.");
    }

    public static function decimalFromMinorUnits(int $minorUnits): string
    {
        if ($minorUnits < 0) {
            throw new InvalidArgumentException('Monetary values cannot be negative.');
        }

        return BigDecimal::ofUnscaledValue($minorUnits, self::SCALE)
            ->toScale(self::SCALE)
            ->toString();
    }

    public static function rateUnitsFromDecimal(mixed $value, string $field = 'monetary value'): int
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match('/^(0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $value) !== 1) {
            throw new InvalidArgumentException("{$field} must be a non-negative decimal value.");
        }

        try {
            $rateUnits = BigDecimal::of($value)
                ->toScale(6)
                ->getUnscaledValue();
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException("{$field} has more precision than the exact rate format supports.", previous: $exception);
        }

        return self::toInt($rateUnits, "{$field} is outside the supported range.");
    }

    public static function canonicalRateUnits(mixed $value, string $field = 'rate'): int
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw new InvalidArgumentException("{$field} cannot be negative.");
            }

            return $value;
        }

        if (! is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/', $value) !== 1) {
            throw new InvalidArgumentException("{$field} must be a non-negative integer rate unit value.");
        }

        return self::toInt(BigInteger::of($value), "{$field} is outside the supported range.");
    }

    public static function rateUnitsFromMinorUnits(int $minorUnits): int
    {
        if ($minorUnits < 0) {
            throw new InvalidArgumentException('Monetary values cannot be negative.');
        }

        return self::toInt(
            BigInteger::of((string) $minorUnits)->multipliedBy('10000'),
            'The exact rate is outside the supported range.',
        );
    }

    public static function minorUnitsFromRateUnitsCeiling(int $rateUnits): int
    {
        if ($rateUnits < 0) {
            throw new InvalidArgumentException('Monetary values cannot be negative.');
        }

        [$minorUnits, $remainder] = BigInteger::of((string) $rateUnits)->quotientAndRemainder('10000');
        if (! $remainder->isZero()) {
            $minorUnits = $minorUnits->plus(1);
        }

        return self::toInt($minorUnits, 'The monetary value is outside the supported range.');
    }

    public static function decimalFromRateUnits(int $rateUnits): string
    {
        if ($rateUnits < 0) {
            throw new InvalidArgumentException('Monetary values cannot be negative.');
        }

        return BigDecimal::ofUnscaledValue($rateUnits, 6)
            ->toScale(6)
            ->toString();
    }

    public static function displayDecimalFromRateUnits(int $rateUnits): string
    {
        $decimal = self::decimalFromRateUnits($rateUnits);
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $fraction = rtrim($fraction, '0');
        $fraction = str_pad($fraction, 2, '0');

        return $whole.'.'.$fraction;
    }

    public static function rateUnitsFromPerTokenDecimal(mixed $value, string $field = 'token rate'): int
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match('/^(0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $value) !== 1) {
            throw new InvalidArgumentException("{$field} must be a non-negative decimal value.");
        }

        try {
            $rateUnits = BigDecimal::of($value)
                ->multipliedBy((string) self::RATE_SCALE)
                ->toScale(6)
                ->getUnscaledValue();
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException("{$field} has more precision than the exact rate format supports.", previous: $exception);
        }

        return self::toInt($rateUnits, "{$field} is outside the supported range.");
    }

    private static function toInt(BigInteger $value, string $message): int
    {
        $minimum = BigInteger::of('0');
        $maximum = BigInteger::of((string) PHP_INT_MAX);

        if ($value->isLessThan($minimum) || $value->isGreaterThan($maximum)) {
            throw new InvalidArgumentException($message);
        }

        return $value->toInt();
    }
}
