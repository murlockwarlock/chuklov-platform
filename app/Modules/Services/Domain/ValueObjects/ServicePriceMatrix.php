<?php

namespace App\Modules\Services\Domain\ValueObjects;

use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Finance\Domain\ValueObjects\Money;
use InvalidArgumentException;

final readonly class ServicePriceMatrix
{
    /** @param array<string, int> $minorUnits */
    private function __construct(private array $minorUnits) {}

    public static function fromMajor(mixed $value): self
    {
        if ($value === null || $value === []) {
            return new self([]);
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('The service price matrix is invalid.');
        }

        $minorUnits = [];
        foreach ($value as $currency => $amount) {
            if (! is_string($currency) || trim($currency) === '') {
                throw new InvalidArgumentException('The service price matrix currency is invalid.');
            }

            $money = Money::fromDecimal(
                is_int($amount) ? $amount : (string) $amount,
                app(CurrencyCatalog::class)->code(strtoupper(trim($currency))),
            );
            $money->assertPositive();
            $minorUnits[$money->currency()->value] = $money->minorUnits();
        }

        ksort($minorUnits);

        return new self($minorUnits);
    }

    public static function fromMinor(mixed $value): self
    {
        if ($value === null || $value === []) {
            return new self([]);
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('The service price matrix is invalid.');
        }

        $minorUnits = [];
        foreach ($value as $currency => $amount) {
            if (! is_string($currency) || trim($currency) === '') {
                throw new InvalidArgumentException('The service price matrix currency is invalid.');
            }

            if (! is_int($amount)
                && (! is_string($amount) || preg_match('/^(0|[1-9][0-9]*)$/', $amount) !== 1)) {
                throw new InvalidArgumentException('The service price matrix amount is invalid.');
            }

            $money = Money::ofMinor($amount, app(CurrencyCatalog::class)->code(strtoupper(trim($currency))));
            $money->assertPositive();
            $minorUnits[$money->currency()->value] = $money->minorUnits();
        }

        ksort($minorUnits);

        return new self($minorUnits);
    }

    /** @return array<string, int> */
    public function minorUnits(): array
    {
        return $this->minorUnits;
    }

    /** @return array<string, string> */
    public function majorUnits(): array
    {
        $result = [];

        foreach ($this->minorUnits as $currency => $minor) {
            $result[$currency] = Money::ofMinor($minor, $currency)->toDecimalString();
        }

        return $result;
    }
}
