<?php

namespace Tests\Unit\Finance;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Finance\Domain\ValueObjects\Money;
use InvalidArgumentException;
use Tests\TestCase;

final class MoneyTest extends TestCase
{
    public function test_it_constructs_exact_minor_units_and_formats_without_float_arithmetic(): void
    {
        $money = Money::fromDecimal('100.25', CurrencyCode::RUB);

        self::assertSame(10025, $money->minorUnits());
        self::assertSame('100.25', $money->toDecimalString());
        self::assertSame(CurrencyCode::RUB, $money->currency());
    }

    public function test_it_supports_zero_and_non_two_decimal_currencies(): void
    {
        self::assertTrue(Money::zero(CurrencyCode::JPY)->isZero());
        self::assertSame(123, Money::fromDecimal('123', CurrencyCode::JPY)->minorUnits());
        self::assertSame(0, app(CurrencyCatalog::class)->scale(CurrencyCode::JPY));
    }

    public function test_it_rejects_precision_that_currency_cannot_represent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('1.001', CurrencyCode::RUB);
    }

    public function test_it_rejects_invalid_and_overflowing_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::ofMinor('9223372036854775808', CurrencyCode::RUB);
    }

    public function test_it_requires_matching_currencies_for_arithmetic(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('1', CurrencyCode::RUB)->add(Money::fromDecimal('1', CurrencyCode::USD));
    }

    public function test_it_converts_with_explicit_rate_and_rounding(): void
    {
        $money = Money::fromDecimal('10.00', CurrencyCode::USD)
            ->convert(CurrencyCode::RUB, '91.235', FinancialRoundingMode::HalfUp);

        self::assertSame(91235, $money->minorUnits());
        self::assertSame('912.35', $money->toDecimalString());
    }

    public function test_it_rejects_non_positive_or_imprecise_rates(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('1', CurrencyCode::USD)
            ->convert(CurrencyCode::RUB, '0', FinancialRoundingMode::HalfUp);
    }
}
