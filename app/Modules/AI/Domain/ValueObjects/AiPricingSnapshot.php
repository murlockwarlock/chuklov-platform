<?php

namespace App\Modules\AI\Domain\ValueObjects;

final readonly class AiPricingSnapshot
{
    public function __construct(
        public string $currency = 'USD',
        public int $inputCostPerMillionMinorUnits = 0,
        public int $outputCostPerMillionMinorUnits = 0,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            currency: (string) ($data['currency'] ?? 'USD'),
            inputCostPerMillionMinorUnits: (int) ($data['input_cost_per_million_minor_units'] ?? $data['input_price_per_million'] ?? 0),
            outputCostPerMillionMinorUnits: (int) ($data['output_cost_per_million_minor_units'] ?? $data['output_price_per_million'] ?? 0),
        );
    }

    public function calculateCostMinorUnits(int $promptTokens, int $completionTokens): int
    {
        $inputCostRaw = ($promptTokens / 1_000_000.0) * $this->inputCostPerMillionMinorUnits;
        $outputCostRaw = ($completionTokens / 1_000_000.0) * $this->outputCostPerMillionMinorUnits;
        $totalRaw = $inputCostRaw + $outputCostRaw;

        if ($totalRaw > 0.0 && $totalRaw < 1.0) {
            return 1;
        }

        return (int) ceil($totalRaw);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'input_cost_per_million_minor_units' => $this->inputCostPerMillionMinorUnits,
            'output_cost_per_million_minor_units' => $this->outputCostPerMillionMinorUnits,
        ];
    }
}
