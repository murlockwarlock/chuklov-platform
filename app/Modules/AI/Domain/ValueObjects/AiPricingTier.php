<?php

namespace App\Modules\AI\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class AiPricingTier
{
    public function __construct(
        public int $minimumInputTokens,
        public ?int $maximumInputTokens,
        public int $inputRatePerMillionUnits,
        public int $outputRatePerMillionUnits,
        public ?int $cacheReadRatePerMillionUnits = null,
        public ?int $cacheWriteRatePerMillionUnits = null,
        public ?int $reasoningRatePerMillionUnits = null,
    ) {
        if ($this->minimumInputTokens < 0
            || ($this->maximumInputTokens !== null && $this->maximumInputTokens < $this->minimumInputTokens)
            || $this->inputRatePerMillionUnits < 0
            || $this->outputRatePerMillionUnits < 0
            || ($this->cacheReadRatePerMillionUnits !== null && $this->cacheReadRatePerMillionUnits < 0)
            || ($this->cacheWriteRatePerMillionUnits !== null && $this->cacheWriteRatePerMillionUnits < 0)
            || ($this->reasoningRatePerMillionUnits !== null && $this->reasoningRatePerMillionUnits < 0)) {
            throw new InvalidArgumentException('The AI pricing tier is invalid.');
        }
    }

    public function contains(int $inputTokens): bool
    {
        return $inputTokens >= $this->minimumInputTokens
            && ($this->maximumInputTokens === null || $inputTokens <= $this->maximumInputTokens);
    }

    /** @return array<string, int|null> */
    public function toArray(): array
    {
        return [
            'minimum_input_tokens' => $this->minimumInputTokens,
            'maximum_input_tokens' => $this->maximumInputTokens,
            'input_rate_per_million_units' => $this->inputRatePerMillionUnits,
            'output_rate_per_million_units' => $this->outputRatePerMillionUnits,
            'cache_read_input_rate_per_million_units' => $this->cacheReadRatePerMillionUnits,
            'cache_write_input_rate_per_million_units' => $this->cacheWriteRatePerMillionUnits,
            'reasoning_rate_per_million_units' => $this->reasoningRatePerMillionUnits,
        ];
    }
}
