<?php

namespace App\Modules\Finance\Domain\ValueObjects;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use InvalidArgumentException;

final readonly class Money
{
    private function __construct(
        private int $minorUnits,
        private CurrencyCode $currency,
    ) {}

    public static function zero(CurrencyCode|string $currency): self
    {
        return new self(0, app(CurrencyCatalog::class)->code($currency));
    }

    public static function ofMinor(int|string $minorUnits, CurrencyCode|string $currency): self
    {
        if (is_string($minorUnits) && preg_match('/^-?(0|[1-9][0-9]*)$/', $minorUnits) !== 1) {
            throw new InvalidArgumentException('The money amount is invalid.');
        }

        $minor = BigInteger::of($minorUnits);
        $minimum = BigInteger::of((string) PHP_INT_MIN);
        $maximum = BigInteger::of((string) PHP_INT_MAX);

        if ($minor->isLessThan($minimum) || $minor->isGreaterThan($maximum)) {
            throw new InvalidArgumentException('The money amount is outside the supported range.');
        }

        return new self($minor->toInt(), app(CurrencyCatalog::class)->code($currency));
    }

    public static function fromDecimal(string|int $amount, CurrencyCode|string $currency): self
    {
        if (is_int($amount)) {
            $amount = (string) $amount;
        }

        if (preg_match('/^-?(0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $amount) !== 1) {
            throw new InvalidArgumentException('The decimal money amount is invalid.');
        }

        $code = app(CurrencyCatalog::class)->code($currency);
        $scale = app(CurrencyCatalog::class)->scale($code);

        try {
            $minor = BigDecimal::of($amount)->toScale($scale)->getUnscaledValue();
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('The decimal money amount has unsupported precision.', previous: $exception);
        }

        return self::ofMinor($minor->toString(), $code);
    }

    public function minorUnits(): int
    {
        return $this->minorUnits;
    }

    public function minorUnitsString(): string
    {
        return (string) $this->minorUnits;
    }

    public function currency(): CurrencyCode
    {
        return $this->currency;
    }

    /** @return int<0, max> */
    public function scale(): int
    {
        return app(CurrencyCatalog::class)->scale($this->currency);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function add(self $other): self
    {
        $this->assertCurrency($other);

        return self::ofMinor(BigInteger::of($this->minorUnits)->plus($other->minorUnits)->toString(), $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertCurrency($other);

        return self::ofMinor(BigInteger::of($this->minorUnits)->minus($other->minorUnits)->toString(), $this->currency);
    }

    public function compareTo(self $other): int
    {
        $this->assertCurrency($other);

        return $this->minorUnits <=> $other->minorUnits;
    }

    public function toDecimalString(): string
    {
        return BigDecimal::ofUnscaledValue($this->minorUnits, $this->scale())->toScale($this->scale())->toString();
    }

    public function convert(
        CurrencyCode|string $targetCurrency,
        string $rate,
        FinancialRoundingMode $roundingMode,
    ): self {
        $catalog = app(CurrencyCatalog::class);
        $target = $catalog->code($targetCurrency);

        if (preg_match('/^(?:0|[1-9][0-9]{0,19})(?:\.[0-9]{1,18})?$/', $rate) !== 1 || BigDecimal::of($rate)->isNegativeOrZero()) {
            throw new InvalidArgumentException('The exchange rate is invalid.');
        }

        $converted = BigDecimal::ofUnscaledValue($this->minorUnits, $this->scale())
            ->multipliedBy(BigDecimal::of($rate))
            ->toScale($catalog->scale($target), $roundingMode->brick())
            ->getUnscaledValue();

        return self::ofMinor($converted->toString(), $target);
    }

    public function assertPositive(): void
    {
        if (! $this->isPositive()) {
            throw new InvalidArgumentException('The money amount must be positive.');
        }
    }

    private function assertCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Money values must use the same currency.');
        }
    }
}
