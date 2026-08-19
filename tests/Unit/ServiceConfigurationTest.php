<?php

namespace Tests\Unit;

use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Services\Domain\ValueObjects\ServiceConfiguration;
use InvalidArgumentException;
use Tests\TestCase;

final class ServiceConfigurationTest extends TestCase
{
    public function test_kzt_major_integer_is_converted_to_minor_units(): void
    {
        self::assertSame(1_500_000, $this->configuration('15000', 'KZT')->priceMinor);
    }

    public function test_kzt_major_decimal_is_converted_without_precision_loss(): void
    {
        self::assertSame(1_500_050, $this->configuration('15000.50', 'KZT')->priceMinor);
    }

    public function test_jpy_major_integer_uses_zero_currency_scale(): void
    {
        self::assertSame(15_000, $this->configuration('15000', 'JPY')->priceMinor);
    }

    public function test_jpy_fraction_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->configuration('15000.50', 'JPY');
    }

    public function test_unsupported_fractional_precision_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->configuration('15000.001', 'RUB');
    }

    public function test_existing_minor_amount_hydrates_to_the_same_major_value(): void
    {
        $created = $this->configuration('15000.50', 'KZT');
        $hydrated = ServiceConfiguration::from([
            ...$this->baseAttributes(),
            'price_minor' => $created->priceMinor,
            'price_currency' => 'KZT',
        ]);

        self::assertSame($created->priceMinor, $hydrated->priceMinor);
        self::assertSame('15000.50', Money::ofMinor(
            $hydrated->priceMinor,
            $hydrated->priceCurrency,
        )->toDecimalString());
    }

    public function test_no_price_remains_null_in_both_persisted_fields(): void
    {
        $configuration = ServiceConfiguration::from($this->baseAttributes());

        self::assertNull($configuration->priceMinor);
        self::assertNull($configuration->priceCurrency);
    }

    /** @return array<string, mixed> */
    private function baseAttributes(): array
    {
        return [
            'name' => 'Consultation',
            'summary' => 'Initial consultation.',
            'formats' => [],
        ];
    }

    private function configuration(string $price, string $currency): ServiceConfiguration
    {
        return ServiceConfiguration::from([
            ...$this->baseAttributes(),
            'price' => $price,
            'price_currency' => $currency,
        ]);
    }
}
