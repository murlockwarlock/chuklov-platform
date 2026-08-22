<?php

namespace App\Modules\AI\Domain\ValueObjects;

use App\Modules\AI\Domain\Exceptions\AiPricingProfileIncompleteException;
use Brick\Math\BigInteger;
use InvalidArgumentException;

final readonly class AiPricingSnapshot
{
    public const string SOURCE_CATALOG = 'catalog';

    public const string SOURCE_MANUAL = 'manual';

    public const string SOURCE_UNKNOWN = 'unknown';

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
        public string $pricingSource = self::SOURCE_MANUAL,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $source = (string) ($data['pricing_source'] ?? (
            array_key_exists('input_cost_per_million_minor_units', $data)
                || array_key_exists('input_price_per_million', $data)
                || array_key_exists('output_cost_per_million_minor_units', $data)
                || array_key_exists('output_price_per_million', $data)
                ? self::SOURCE_MANUAL
                : self::SOURCE_UNKNOWN
        ));

        if (! in_array($source, [self::SOURCE_CATALOG, self::SOURCE_MANUAL, self::SOURCE_UNKNOWN], true)) {
            throw new InvalidArgumentException('The AI pricing source is invalid.');
        }

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
            pricingSource: $source,
        );
    }

    public function isComplete(): bool
    {
        return $this->currency !== ''
            && $this->pricingSource !== self::SOURCE_UNKNOWN
            && $this->inputCostPerMillionMinorUnits >= 0
            && $this->outputCostPerMillionMinorUnits >= 0
            && ($this->pricingSource === self::SOURCE_CATALOG
                ? ($this->cacheReadInputCostPerMillionMinorUnits === null || $this->cacheReadInputCostPerMillionMinorUnits >= 0)
                    && ($this->cacheWriteInputCostPerMillionMinorUnits === null || $this->cacheWriteInputCostPerMillionMinorUnits >= 0)
                    && ($this->reasoningCostPerMillionMinorUnits === null || $this->reasoningCostPerMillionMinorUnits >= 0)
                : $this->cacheReadInputCostPerMillionMinorUnits !== null
                    && $this->cacheReadInputCostPerMillionMinorUnits >= 0
                    && $this->cacheWriteInputCostPerMillionMinorUnits !== null
                    && $this->cacheWriteInputCostPerMillionMinorUnits >= 0
                    && $this->reasoningCostPerMillionMinorUnits !== null
                    && $this->reasoningCostPerMillionMinorUnits >= 0)
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

        $scale = BigInteger::of('1000000');
        $totalScaledCost = BigInteger::zero()
            ->plus(BigInteger::of((string) $promptTokens)->multipliedBy($this->inputCostPerMillionMinorUnits))
            ->plus(BigInteger::of((string) $completionTokens)->multipliedBy($this->outputCostPerMillionMinorUnits))
            ->plus(BigInteger::of((string) $cacheReadInputTokens)->multipliedBy($this->cacheReadInputCostPerMillionMinorUnits ?? 0))
            ->plus(BigInteger::of((string) $cacheWriteInputTokens)->multipliedBy($this->cacheWriteInputCostPerMillionMinorUnits ?? 0))
            ->plus(BigInteger::of((string) $reasoningTokens)->multipliedBy($this->reasoningCostPerMillionMinorUnits ?? 0));

        if ($this->fixedRequestCostApplicable) {
            $totalScaledCost = $totalScaledCost->plus(
                BigInteger::of((string) $providerRequests)
                    ->multipliedBy((int) $this->fixedRequestCostMinorUnits)
                    ->multipliedBy($scale),
            );
        }

        [$wholeCost, $remainder] = $totalScaledCost->quotientAndRemainder($scale);
        if (! $remainder->isZero()) {
            $wholeCost = $wholeCost->plus(1);
        }

        try {
            return $wholeCost->toInt();
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('The calculated AI cost is outside the supported range.', previous: $exception);
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...$this->toArrayWithoutSource(),
            'pricing_source' => $this->pricingSource,
        ];
    }

    /** @return array<string, mixed> */
    public function toArrayWithoutSource(): array
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
