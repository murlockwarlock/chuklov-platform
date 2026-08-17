<?php

namespace App\Modules\AI\Domain\ValueObjects;

use App\Modules\AI\Domain\Exceptions\AiPricingProfileIncompleteException;

final readonly class AiPricingSnapshot
{
    public function __construct(
        public string $currency = 'USD',
        public int $inputCostPerMillionMinorUnits = 0,
        public int $outputCostPerMillionMinorUnits = 0,
        public ?int $cacheReadInputCostPerMillionMinorUnits = 0,
        public ?int $cacheWriteInputCostPerMillionMinorUnits = 0,
        public ?int $reasoningCostPerMillionMinorUnits = 0,
        public bool $fixedRequestCostApplicable = false,
        public ?int $fixedRequestCostMinorUnits = 0,
        /** @var list<string> */
        public array $unsupportedMeters = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            currency: (string) ($data['currency'] ?? 'USD'),
            inputCostPerMillionMinorUnits: (int) ($data['input_cost_per_million_minor_units'] ?? $data['input_price_per_million'] ?? 0),
            outputCostPerMillionMinorUnits: (int) ($data['output_cost_per_million_minor_units'] ?? $data['output_price_per_million'] ?? 0),
            cacheReadInputCostPerMillionMinorUnits: array_key_exists('cache_read_input_cost_per_million_minor_units', $data)
                ? self::nullableInt($data['cache_read_input_cost_per_million_minor_units'])
                : null,
            cacheWriteInputCostPerMillionMinorUnits: array_key_exists('cache_write_input_cost_per_million_minor_units', $data)
                ? self::nullableInt($data['cache_write_input_cost_per_million_minor_units'])
                : null,
            reasoningCostPerMillionMinorUnits: array_key_exists('reasoning_cost_per_million_minor_units', $data)
                ? self::nullableInt($data['reasoning_cost_per_million_minor_units'])
                : null,
            fixedRequestCostApplicable: (bool) ($data['fixed_request_cost_applicable'] ?? false),
            fixedRequestCostMinorUnits: array_key_exists('fixed_request_cost_minor_units', $data)
                ? self::nullableInt($data['fixed_request_cost_minor_units'])
                : null,
            unsupportedMeters: array_values(array_map('strval', (array) ($data['unsupported_meters'] ?? []))),
        );
    }

    public function isComplete(): bool
    {
        return $this->currency !== ''
            && $this->inputCostPerMillionMinorUnits >= 0
            && $this->outputCostPerMillionMinorUnits >= 0
            && $this->cacheReadInputCostPerMillionMinorUnits !== null
            && $this->cacheReadInputCostPerMillionMinorUnits >= 0
            && $this->cacheWriteInputCostPerMillionMinorUnits !== null
            && $this->cacheWriteInputCostPerMillionMinorUnits >= 0
            && $this->reasoningCostPerMillionMinorUnits !== null
            && $this->reasoningCostPerMillionMinorUnits >= 0
            && (! $this->fixedRequestCostApplicable
                || ($this->fixedRequestCostMinorUnits !== null && $this->fixedRequestCostMinorUnits >= 0))
            && $this->unsupportedMeters === [];
    }

    public function assertComplete(): void
    {
        if (! $this->isComplete()) {
            throw new AiPricingProfileIncompleteException('The immutable AI billing profile is incomplete for bounded execution.');
        }
    }

    public function calculateCostMinorUnits(
        int $promptTokens,
        int $completionTokens,
        int $cacheReadInputTokens = 0,
        int $cacheWriteInputTokens = 0,
        int $reasoningTokens = 0,
        int $providerRequests = 0,
    ): int {
        $this->assertComplete();

        $promptTokens = max(0, $promptTokens);
        $completionTokens = max(0, $completionTokens);
        $cacheReadInputTokens = max(0, $cacheReadInputTokens);
        $cacheWriteInputTokens = max(0, $cacheWriteInputTokens);
        $reasoningTokens = max(0, $reasoningTokens);
        $providerRequests = max(0, $providerRequests);

        $inputCostRaw = ($promptTokens / 1_000_000.0) * $this->inputCostPerMillionMinorUnits;
        $outputCostRaw = ($completionTokens / 1_000_000.0) * $this->outputCostPerMillionMinorUnits;
        $cacheReadCostRaw = ($cacheReadInputTokens / 1_000_000.0) * $this->cacheReadInputCostPerMillionMinorUnits;
        $cacheWriteCostRaw = ($cacheWriteInputTokens / 1_000_000.0) * $this->cacheWriteInputCostPerMillionMinorUnits;
        $reasoningCostRaw = ($reasoningTokens / 1_000_000.0) * $this->reasoningCostPerMillionMinorUnits;
        $requestCostRaw = $this->fixedRequestCostApplicable
            ? $providerRequests * (int) $this->fixedRequestCostMinorUnits
            : 0;
        $totalRaw = $inputCostRaw + $outputCostRaw + $cacheReadCostRaw + $cacheWriteCostRaw + $reasoningCostRaw + $requestCostRaw;

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
            'cache_read_input_cost_per_million_minor_units' => $this->cacheReadInputCostPerMillionMinorUnits,
            'cache_write_input_cost_per_million_minor_units' => $this->cacheWriteInputCostPerMillionMinorUnits,
            'reasoning_cost_per_million_minor_units' => $this->reasoningCostPerMillionMinorUnits,
            'fixed_request_cost_applicable' => $this->fixedRequestCostApplicable,
            'fixed_request_cost_minor_units' => $this->fixedRequestCostMinorUnits,
            'unsupported_meters' => $this->unsupportedMeters,
        ];
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
