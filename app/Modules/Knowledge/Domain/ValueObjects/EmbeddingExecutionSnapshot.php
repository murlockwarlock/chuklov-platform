<?php

namespace App\Modules\Knowledge\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class EmbeddingExecutionSnapshot
{
    public function __construct(
        public EmbeddingConfiguration $configuration,
        public EmbeddingPricingPolicy $pricing,
    ) {
        $this->pricing->assertCompatible($this->configuration);
    }

    public static function active(): self
    {
        return new self(
            configuration: EmbeddingConfiguration::active(),
            pricing: EmbeddingPricingPolicy::active(),
        );
    }

    /** @param array<string, mixed> $snapshot */
    public static function fromArray(array $snapshot): self
    {
        $configuration = $snapshot['configuration_snapshot'] ?? null;
        $pricing = $snapshot['pricing_snapshot'] ?? null;
        if (! is_array($configuration) || ! is_array($pricing)) {
            throw new InvalidArgumentException('Embedding execution snapshot is invalid.');
        }

        return new self(
            configuration: EmbeddingConfiguration::fromArray($configuration),
            pricing: EmbeddingPricingPolicy::fromArray($pricing),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'configuration_snapshot' => $this->configuration->toArray(),
            'pricing_snapshot' => $this->pricing->toArray(),
        ];
    }

    public function assertCurrent(): void
    {
        $activeConfiguration = EmbeddingConfiguration::active();
        $activePricing = EmbeddingPricingPolicy::active();

        $this->configuration->assertSame($activeConfiguration);
        $this->pricing->assertSame($activePricing);
        $this->pricing->assertCompatible($this->configuration);
        $activePricing->assertCompatible($activeConfiguration);
    }
}
