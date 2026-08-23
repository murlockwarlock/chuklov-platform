<?php

namespace App\Modules\Knowledge\Domain\ValueObjects;

use App\Modules\AI\Domain\ValueObjects\AiMoney;
use Brick\Math\BigInteger;
use InvalidArgumentException;

final readonly class EmbeddingPricingPolicy
{
    private const int TOKENS_PER_MILLION = 1_000_000;

    private const int MICRO_UNITS_PER_MINOR_UNIT = 10_000;

    public function __construct(
        public string $provider,
        public string $model,
        public string $configurationVersion,
        public string $currency,
        public ?int $inputCostPerMillionMinorUnits,
        public bool $zeroCostLocal = false,
        public ?int $inputRatePerMillionUnits = null,
        public ?string $catalogSource = null,
        public ?string $pricingAsOf = null,
    ) {}

    public static function active(): self
    {
        $pricing = (array) config('rag.embedding.pricing', []);

        return new self(
            provider: (string) ($pricing['provider'] ?? ''),
            model: (string) ($pricing['model'] ?? ''),
            configurationVersion: (string) ($pricing['configuration_version'] ?? ''),
            currency: (string) ($pricing['currency'] ?? ''),
            inputCostPerMillionMinorUnits: self::compatibilityMinor(self::configuredRate($pricing)),
            zeroCostLocal: (bool) ($pricing['zero_cost_local'] ?? false),
            inputRatePerMillionUnits: self::configuredRate($pricing),
            catalogSource: self::optionalString($pricing['catalog_source'] ?? null),
            pricingAsOf: self::optionalString($pricing['pricing_as_of'] ?? null),
        );
    }

    /** @param array<string, mixed> $snapshot */
    public static function fromArray(array $snapshot): self
    {
        $provider = $snapshot['provider'] ?? null;
        $model = $snapshot['model'] ?? null;
        $configurationVersion = $snapshot['configuration_version'] ?? null;
        $currency = $snapshot['currency'] ?? null;
        $zeroCostLocal = $snapshot['zero_cost_local'] ?? null;
        $catalogSource = $snapshot['catalog_source'] ?? null;
        $pricingAsOf = $snapshot['pricing_as_of'] ?? null;

        if (! is_string($provider)
            || ! is_string($model)
            || ! is_string($configurationVersion)
            || ! is_string($currency)
            || ! is_bool($zeroCostLocal)) {
            throw new InvalidArgumentException('Embedding pricing snapshot is invalid.');
        }
        if ($catalogSource !== null && ! is_string($catalogSource)) {
            throw new InvalidArgumentException('Embedding pricing source is invalid.');
        }
        if ($pricingAsOf !== null && ! is_string($pricingAsOf)) {
            throw new InvalidArgumentException('Embedding pricing date is invalid.');
        }

        $rate = self::snapshotRate($snapshot);

        return new self(
            provider: $provider,
            model: $model,
            configurationVersion: $configurationVersion,
            currency: $currency,
            inputCostPerMillionMinorUnits: self::compatibilityMinor($rate),
            zeroCostLocal: $zeroCostLocal,
            inputRatePerMillionUnits: $rate,
            catalogSource: $catalogSource,
            pricingAsOf: $pricingAsOf,
        );
    }

    public function assertCompatible(EmbeddingConfiguration $configuration): void
    {
        $rate = $this->inputRatePerMillionUnits();
        if ($this->provider !== $configuration->provider
            || $this->model !== $configuration->model
            || $this->configurationVersion !== $configuration->version
            || $this->currency === ''
            || ($rate === null && ! $this->zeroCostLocal)
            || ($this->zeroCostLocal && $rate !== null && $rate > 0)
            || ($rate !== null && $rate < 0)) {
            throw new InvalidArgumentException('Embedding pricing policy is unavailable for the active configuration.');
        }
    }

    public function assertSame(self $pricing): void
    {
        if ($this->toArray() !== $pricing->toArray()) {
            throw new InvalidArgumentException('Embedding pricing changed after AI run preparation.');
        }
    }

    public function inputRatePerMillionUnits(): ?int
    {
        return $this->inputRatePerMillionUnits
            ?? ($this->inputCostPerMillionMinorUnits === null
                ? null
                : AiMoney::rateUnitsFromMinorUnits($this->inputCostPerMillionMinorUnits));
    }

    public function estimateCostForQuery(string $query): int
    {
        if ($this->zeroCostLocal) {
            return 0;
        }

        $rate = $this->inputRatePerMillionUnits();
        if ($rate === null) {
            throw new InvalidArgumentException('Embedding pricing policy is unavailable.');
        }

        $numerator = BigInteger::of((string) strlen($query))->multipliedBy($rate);
        [$minorUnits, $remainder] = $numerator->quotientAndRemainder(
            BigInteger::of((string) (self::TOKENS_PER_MILLION * self::MICRO_UNITS_PER_MINOR_UNIT)),
        );
        if (! $remainder->isZero()) {
            $minorUnits = $minorUnits->plus(1);
        }

        try {
            return $minorUnits->toInt();
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('The embedding cost is outside the supported range.', previous: $exception);
        }
    }

    public function maximumQueryCost(): int
    {
        return $this->estimateCostForQuery(str_repeat('x', EmbeddingConfiguration::MAX_QUERY_BYTES));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $rate = $this->inputRatePerMillionUnits();

        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'configuration_version' => $this->configurationVersion,
            'currency' => $this->currency,
            'pricing_schema_version' => 2,
            'rate_scale' => AiMoney::RATE_SCALE,
            'input_cost_per_million_minor_units' => $this->inputCostPerMillionMinorUnits,
            'input_rate_per_million_units' => $rate,
            'zero_cost_local' => $this->zeroCostLocal,
            'catalog_source' => $this->catalogSource,
            'pricing_as_of' => $this->pricingAsOf,
        ];
    }

    /** @param array<string, mixed> $pricing */
    private static function configuredRate(array $pricing): ?int
    {
        if (array_key_exists('input_rate_per_million_units', $pricing)) {
            return $pricing['input_rate_per_million_units'] === null
                ? null
                : AiMoney::canonicalRateUnits($pricing['input_rate_per_million_units'], 'embedding input rate');
        }

        if (array_key_exists('input_price_per_million', $pricing)) {
            return $pricing['input_price_per_million'] === null || $pricing['input_price_per_million'] === ''
                ? null
                : AiMoney::rateUnitsFromDecimal($pricing['input_price_per_million'], 'embedding input price');
        }

        if (! array_key_exists('input_cost_per_million_minor_units', $pricing)
            || $pricing['input_cost_per_million_minor_units'] === null) {
            return null;
        }

        return AiMoney::rateUnitsFromMinorUnits(
            AiMoney::canonicalMinorUnits($pricing['input_cost_per_million_minor_units'], 'embedding input cost'),
        );
    }

    /** @param array<string, mixed> $snapshot */
    private static function snapshotRate(array $snapshot): ?int
    {
        if (array_key_exists('input_rate_per_million_units', $snapshot)) {
            return $snapshot['input_rate_per_million_units'] === null
                ? null
                : AiMoney::canonicalRateUnits($snapshot['input_rate_per_million_units'], 'embedding input rate');
        }

        if (array_key_exists('input_price_per_million', $snapshot)) {
            return $snapshot['input_price_per_million'] === null
                ? null
                : AiMoney::rateUnitsFromDecimal($snapshot['input_price_per_million'], 'embedding input price');
        }

        if (! array_key_exists('input_cost_per_million_minor_units', $snapshot)) {
            return null;
        }

        return $snapshot['input_cost_per_million_minor_units'] === null
            ? null
            : AiMoney::rateUnitsFromMinorUnits(
                AiMoney::canonicalMinorUnits($snapshot['input_cost_per_million_minor_units'], 'embedding input cost'),
            );
    }

    private static function compatibilityMinor(?int $rate): ?int
    {
        return $rate === null ? null : intdiv($rate, self::MICRO_UNITS_PER_MINOR_UNIT);
    }

    private static function optionalString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Embedding pricing provenance is invalid.');
        }

        return trim($value);
    }
}
