<?php

namespace App\Modules\Knowledge\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class EmbeddingPricingPolicy
{
    public function __construct(
        public string $provider,
        public string $model,
        public string $configurationVersion,
        public string $currency,
        public ?int $inputCostPerMillionMinorUnits,
        public bool $zeroCostLocal = false,
    ) {}

    public static function active(): self
    {
        $pricing = (array) config('rag.embedding.pricing', []);

        return new self(
            provider: (string) ($pricing['provider'] ?? ''),
            model: (string) ($pricing['model'] ?? ''),
            configurationVersion: (string) ($pricing['configuration_version'] ?? ''),
            currency: (string) ($pricing['currency'] ?? ''),
            inputCostPerMillionMinorUnits: array_key_exists('input_cost_per_million_minor_units', $pricing)
                && $pricing['input_cost_per_million_minor_units'] !== null
                ? (int) $pricing['input_cost_per_million_minor_units']
                : null,
            zeroCostLocal: (bool) ($pricing['zero_cost_local'] ?? false),
        );
    }

    /** @param array<string, mixed> $snapshot */
    public static function fromArray(array $snapshot): self
    {
        $provider = $snapshot['provider'] ?? null;
        $model = $snapshot['model'] ?? null;
        $configurationVersion = $snapshot['configuration_version'] ?? null;
        $currency = $snapshot['currency'] ?? null;
        $inputCost = $snapshot['input_cost_per_million_minor_units'] ?? null;
        $zeroCostLocal = $snapshot['zero_cost_local'] ?? null;

        if (! is_string($provider)
            || ! is_string($model)
            || ! is_string($configurationVersion)
            || ! is_string($currency)
            || ($inputCost !== null && ! is_int($inputCost))
            || ! is_bool($zeroCostLocal)) {
            throw new InvalidArgumentException('Embedding pricing snapshot is invalid.');
        }

        return new self(
            provider: $provider,
            model: $model,
            configurationVersion: $configurationVersion,
            currency: $currency,
            inputCostPerMillionMinorUnits: $inputCost,
            zeroCostLocal: $zeroCostLocal,
        );
    }

    public function assertCompatible(EmbeddingConfiguration $configuration): void
    {
        if ($this->provider !== $configuration->provider
            || $this->model !== $configuration->model
            || $this->configurationVersion !== $configuration->version
            || $this->currency === ''
            || ($this->inputCostPerMillionMinorUnits === null && ! $this->zeroCostLocal)
            || ($this->zeroCostLocal && $this->inputCostPerMillionMinorUnits !== null && $this->inputCostPerMillionMinorUnits > 0)
            || ($this->inputCostPerMillionMinorUnits !== null && $this->inputCostPerMillionMinorUnits < 0)) {
            throw new InvalidArgumentException('Embedding pricing policy is unavailable for the active configuration.');
        }
    }

    public function assertSame(self $pricing): void
    {
        if ($this->toArray() !== $pricing->toArray()) {
            throw new InvalidArgumentException('Embedding pricing changed after AI run preparation.');
        }
    }

    public function estimateCostForQuery(string $query): int
    {
        if ($this->zeroCostLocal || $this->inputCostPerMillionMinorUnits === 0) {
            return 0;
        }

        if ($this->inputCostPerMillionMinorUnits === null) {
            throw new InvalidArgumentException('Embedding pricing policy is unavailable.');
        }

        $raw = (strlen($query) / 1_000_000.0) * $this->inputCostPerMillionMinorUnits;

        return $raw > 0.0 && $raw < 1.0 ? 1 : (int) ceil($raw);
    }

    public function maximumQueryCost(): int
    {
        return $this->estimateCostForQuery(str_repeat('x', EmbeddingConfiguration::MAX_QUERY_BYTES));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'configuration_version' => $this->configurationVersion,
            'currency' => $this->currency,
            'input_cost_per_million_minor_units' => $this->inputCostPerMillionMinorUnits,
            'zero_cost_local' => $this->zeroCostLocal,
        ];
    }
}
