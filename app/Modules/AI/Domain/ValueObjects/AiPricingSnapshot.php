<?php

namespace App\Modules\AI\Domain\ValueObjects;

use App\Modules\AI\Domain\Exceptions\AiPricingProfileIncompleteException;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class AiPricingSnapshot
{
    public const string SOURCE_CATALOG = 'catalog';

    public const string SOURCE_MANUAL = 'manual';

    public const string SOURCE_UNKNOWN = 'unknown';

    private const int TOKENS_PER_MILLION = 1_000_000;

    private const int MICRO_UNITS_PER_MINOR_UNIT = 10_000;

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
        public ?string $catalogPricingEffectiveFrom = null,
        public ?string $catalogPricingEffectiveUntil = null,
        public ?string $catalogPricingAsOf = null,
        public ?string $catalogSource = null,
        public ?int $inputRatePerMillionUnits = null,
        public ?int $outputRatePerMillionUnits = null,
        public ?int $cacheReadRatePerMillionUnits = null,
        public ?int $cacheWriteRatePerMillionUnits = null,
        public ?int $reasoningRatePerMillionUnits = null,
        public ?int $fixedRequestRateUnits = null,
        /** @var list<mixed> */
        public array $pricingTiers = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $source = (string) ($data['pricing_source'] ?? (
            self::hasAnyRate($data)
                ? self::SOURCE_MANUAL
                : self::SOURCE_UNKNOWN
        ));

        if (! in_array($source, [self::SOURCE_CATALOG, self::SOURCE_MANUAL, self::SOURCE_UNKNOWN], true)) {
            throw new InvalidArgumentException('The AI pricing source is invalid.');
        }

        $catalogPricingEffectiveFrom = $source === self::SOURCE_CATALOG
            ? self::nullableTimestamp($data['catalog_pricing_effective_from'] ?? null, 'catalog_pricing_effective_from')
            : null;
        $catalogPricingEffectiveUntil = $source === self::SOURCE_CATALOG
            ? self::nullableTimestamp($data['catalog_pricing_effective_until'] ?? null, 'catalog_pricing_effective_until')
            : null;
        $catalogPricingAsOf = $source === self::SOURCE_CATALOG
            ? self::nullableDate($data['catalog_pricing_as_of'] ?? null, 'catalog_pricing_as_of')
            : null;
        $catalogSource = $source === self::SOURCE_CATALOG
            ? self::nullableText($data['catalog_source'] ?? null, 'catalog_source')
            : null;

        if ($catalogPricingEffectiveFrom !== null
            && $catalogPricingEffectiveUntil !== null
            && $catalogPricingEffectiveFrom > $catalogPricingEffectiveUntil) {
            throw new InvalidArgumentException('The AI catalog pricing snapshot has an invalid period.');
        }

        $inputRate = self::rate($data, 'input');
        $outputRate = self::rate($data, 'output');
        $cacheReadRate = self::rate($data, 'cache_read_input');
        $cacheWriteRate = self::rate($data, 'cache_write_input');
        $reasoningRate = self::rate($data, 'reasoning');
        $fixedRate = self::rate($data, 'fixed_request');

        return new self(
            currency: self::currency($data['currency'] ?? 'USD'),
            inputCostPerMillionMinorUnits: self::compatibilityMinor($data, 'input', $inputRate),
            outputCostPerMillionMinorUnits: self::compatibilityMinor($data, 'output', $outputRate),
            cacheReadInputCostPerMillionMinorUnits: self::compatibilityNullableMinor($data, 'cache_read_input', $cacheReadRate),
            cacheWriteInputCostPerMillionMinorUnits: self::compatibilityNullableMinor($data, 'cache_write_input', $cacheWriteRate),
            reasoningCostPerMillionMinorUnits: self::compatibilityNullableMinor($data, 'reasoning', $reasoningRate),
            fixedRequestCostApplicable: self::boolean($data['fixed_request_cost_applicable'] ?? false, 'fixed_request_cost_applicable'),
            fixedRequestCostMinorUnits: self::compatibilityNullableMinor($data, 'fixed_request', $fixedRate),
            unsupportedMeters: self::stringList($data['unsupported_meters'] ?? []),
            pricingSource: $source,
            catalogPricingEffectiveFrom: $catalogPricingEffectiveFrom,
            catalogPricingEffectiveUntil: $catalogPricingEffectiveUntil,
            catalogPricingAsOf: $catalogPricingAsOf,
            catalogSource: $catalogSource,
            inputRatePerMillionUnits: $inputRate,
            outputRatePerMillionUnits: $outputRate,
            cacheReadRatePerMillionUnits: $cacheReadRate,
            cacheWriteRatePerMillionUnits: $cacheWriteRate,
            reasoningRatePerMillionUnits: $reasoningRate,
            fixedRequestRateUnits: $fixedRate,
            pricingTiers: self::pricingTiers($data['pricing_tiers'] ?? []),
        );
    }

    public function isComplete(): bool
    {
        if ($this->currency === ''
            || $this->pricingSource === self::SOURCE_UNKNOWN
            || $this->inputRatePerMillionUnits() < 0
            || $this->outputRatePerMillionUnits() < 0
            || $this->unsupportedMeters !== []
            || ! $this->validPricingTiers()) {
            return false;
        }

        $cacheComplete = $this->pricingSource === self::SOURCE_CATALOG
            ? ($this->cacheReadRatePerMillionUnits === null || $this->cacheReadRatePerMillionUnits >= 0)
                && ($this->cacheWriteRatePerMillionUnits === null || $this->cacheWriteRatePerMillionUnits >= 0)
                && ($this->reasoningRatePerMillionUnits === null || $this->reasoningRatePerMillionUnits >= 0)
            : $this->cacheReadRatePerMillionUnits() !== null
                && $this->cacheWriteRatePerMillionUnits() !== null
                && $this->reasoningRatePerMillionUnits() !== null;

        return $cacheComplete
            && (! $this->fixedRequestCostApplicable
                || $this->fixedRequestRateUnits() !== null);
    }

    public function assertComplete(): void
    {
        if (! $this->isComplete()) {
            throw new AiPricingProfileIncompleteException('The immutable AI billing profile is incomplete for bounded execution.');
        }
    }

    public function hasCatalogPricingMetadata(): bool
    {
        return $this->pricingSource === self::SOURCE_CATALOG
            && ($this->catalogPricingEffectiveFrom !== null
                || $this->catalogPricingEffectiveUntil !== null
                || $this->catalogPricingAsOf !== null
                || $this->catalogSource !== null);
    }

    public function sameBillablePricing(self $other): bool
    {
        return $this->billablePricing() === $other->billablePricing();
    }

    public function inputRatePerMillionUnits(): int
    {
        return $this->inputRatePerMillionUnits ?? AiMoney::rateUnitsFromMinorUnits($this->inputCostPerMillionMinorUnits);
    }

    public function outputRatePerMillionUnits(): int
    {
        return $this->outputRatePerMillionUnits ?? AiMoney::rateUnitsFromMinorUnits($this->outputCostPerMillionMinorUnits);
    }

    public function cacheReadRatePerMillionUnits(): ?int
    {
        return $this->cacheReadRatePerMillionUnits
            ?? ($this->cacheReadInputCostPerMillionMinorUnits === null
                ? null
                : AiMoney::rateUnitsFromMinorUnits($this->cacheReadInputCostPerMillionMinorUnits));
    }

    public function cacheWriteRatePerMillionUnits(): ?int
    {
        return $this->cacheWriteRatePerMillionUnits
            ?? ($this->cacheWriteInputCostPerMillionMinorUnits === null
                ? null
                : AiMoney::rateUnitsFromMinorUnits($this->cacheWriteInputCostPerMillionMinorUnits));
    }

    public function reasoningRatePerMillionUnits(): ?int
    {
        return $this->reasoningRatePerMillionUnits
            ?? ($this->reasoningCostPerMillionMinorUnits === null
                ? null
                : AiMoney::rateUnitsFromMinorUnits($this->reasoningCostPerMillionMinorUnits));
    }

    public function fixedRequestRateUnits(): ?int
    {
        return $this->fixedRequestRateUnits
            ?? ($this->fixedRequestCostMinorUnits === null
                ? null
                : AiMoney::rateUnitsFromMinorUnits($this->fixedRequestCostMinorUnits));
    }

    public function inputPricePerMillion(): string
    {
        return AiMoney::decimalFromRateUnits($this->inputRatePerMillionUnits());
    }

    public function outputPricePerMillion(): string
    {
        return AiMoney::decimalFromRateUnits($this->outputRatePerMillionUnits());
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
        $cacheReadRate = $this->cacheReadRatePerMillionUnits();
        $cacheWriteRate = $this->cacheWriteRatePerMillionUnits();
        $reasoningRate = $this->reasoningRatePerMillionUnits();

        if (($cacheReadInputTokens > 0 && $cacheReadRate === null)
            || ($cacheWriteInputTokens > 0 && $cacheWriteRate === null)
            || ($reasoningTokens > 0 && $reasoningRate === null)) {
            throw new AiPricingProfileIncompleteException('The billing profile does not define a rate for every reported provider meter.');
        }

        $tier = $this->pricingTierFor($promptTokens);
        $inputRate = $tier->inputRatePerMillionUnits ?? $this->inputRatePerMillionUnits();
        $outputRate = $tier->outputRatePerMillionUnits ?? $this->outputRatePerMillionUnits();
        $cacheReadRate = $tier->cacheReadRatePerMillionUnits ?? $cacheReadRate;
        $cacheWriteRate = $tier->cacheWriteRatePerMillionUnits ?? $cacheWriteRate;
        $reasoningRate = $tier->reasoningRatePerMillionUnits ?? $reasoningRate;

        if (($cacheReadInputTokens > 0 && $cacheReadRate === null)
            || ($cacheWriteInputTokens > 0 && $cacheWriteRate === null)
            || ($reasoningTokens > 0 && $reasoningRate === null)) {
            throw new AiPricingProfileIncompleteException('The selected billing tier does not define every reported provider meter.');
        }

        $totalNumerator = BigInteger::zero()
            ->plus(BigInteger::of((string) $promptTokens)->multipliedBy($inputRate))
            ->plus(BigInteger::of((string) $completionTokens)->multipliedBy($outputRate))
            ->plus(BigInteger::of((string) $cacheReadInputTokens)->multipliedBy($cacheReadRate ?? 0))
            ->plus(BigInteger::of((string) $cacheWriteInputTokens)->multipliedBy($cacheWriteRate ?? 0))
            ->plus(BigInteger::of((string) $reasoningTokens)->multipliedBy($reasoningRate ?? 0));

        if ($this->fixedRequestCostApplicable) {
            $fixedRate = $this->fixedRequestRateUnits();
            if ($fixedRate === null) {
                throw new AiPricingProfileIncompleteException('The fixed request meter has no exact rate.');
            }

            $totalNumerator = $totalNumerator->plus(
                BigInteger::of((string) $providerRequests)
                    ->multipliedBy($fixedRate)
                    ->multipliedBy(self::TOKENS_PER_MILLION),
            );
        }

        [$minorUnits, $remainder] = $totalNumerator->quotientAndRemainder(
            BigInteger::of((string) (self::TOKENS_PER_MILLION * self::MICRO_UNITS_PER_MINOR_UNIT)),
        );
        if (! $remainder->isZero()) {
            $minorUnits = $minorUnits->plus(1);
        }

        try {
            return $minorUnits->toInt();
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
            'pricing_schema_version' => 2,
            'rate_scale' => AiMoney::RATE_SCALE,
            'input_cost_per_million_minor_units' => $this->inputCostPerMillionMinorUnits,
            'output_cost_per_million_minor_units' => $this->outputCostPerMillionMinorUnits,
            'cache_read_input_cost_per_million_minor_units' => $this->cacheReadInputCostPerMillionMinorUnits,
            'cache_write_input_cost_per_million_minor_units' => $this->cacheWriteInputCostPerMillionMinorUnits,
            'reasoning_cost_per_million_minor_units' => $this->reasoningCostPerMillionMinorUnits,
            'fixed_request_cost_applicable' => $this->fixedRequestCostApplicable,
            'fixed_request_cost_minor_units' => $this->fixedRequestCostMinorUnits,
            'input_rate_per_million_units' => $this->inputRatePerMillionUnits(),
            'output_rate_per_million_units' => $this->outputRatePerMillionUnits(),
            'cache_read_input_rate_per_million_units' => $this->cacheReadRatePerMillionUnits(),
            'cache_write_input_rate_per_million_units' => $this->cacheWriteRatePerMillionUnits(),
            'reasoning_rate_per_million_units' => $this->reasoningRatePerMillionUnits(),
            'fixed_request_rate_units' => $this->fixedRequestRateUnits(),
            'pricing_tiers' => $this->serializedPricingTiers(),
            'unsupported_meters' => $this->unsupportedMeters,
            'catalog_pricing_effective_from' => $this->catalogPricingEffectiveFrom,
            'catalog_pricing_effective_until' => $this->catalogPricingEffectiveUntil,
            'catalog_pricing_as_of' => $this->catalogPricingAsOf,
            'catalog_source' => $this->catalogSource,
        ];
    }

    private function pricingTierFor(int $inputTokens): ?AiPricingTier
    {
        foreach ($this->pricingTiers as $tier) {
            if ($tier instanceof AiPricingTier && $tier->contains($inputTokens)) {
                return $tier;
            }
        }

        return null;
    }

    private function validPricingTiers(): bool
    {
        if ($this->pricingTiers !== []
            && (! ($this->pricingTiers[0] instanceof AiPricingTier) || $this->pricingTiers[0]->minimumInputTokens !== 0)) {
            return false;
        }

        $previousMaximum = null;
        foreach ($this->pricingTiers as $index => $tier) {
            if (! $tier instanceof AiPricingTier
                || ($index > 0 && ($previousMaximum === null || $tier->minimumInputTokens !== $previousMaximum + 1))) {
                return false;
            }

            $previousMaximum = $tier->maximumInputTokens;
        }

        return $this->pricingTiers === [] || $previousMaximum === null;
    }

    /** @return array<string, mixed> */
    private function billablePricing(): array
    {
        return [
            'currency' => $this->currency,
            'input_rate_per_million_units' => $this->inputRatePerMillionUnits(),
            'output_rate_per_million_units' => $this->outputRatePerMillionUnits(),
            'cache_read_input_rate_per_million_units' => $this->cacheReadRatePerMillionUnits(),
            'cache_write_input_rate_per_million_units' => $this->cacheWriteRatePerMillionUnits(),
            'reasoning_rate_per_million_units' => $this->reasoningRatePerMillionUnits(),
            'fixed_request_cost_applicable' => $this->fixedRequestCostApplicable,
            'fixed_request_rate_units' => $this->fixedRequestRateUnits(),
            'unsupported_meters' => $this->unsupportedMeters,
            'pricing_tiers' => $this->serializedPricingTiers(),
        ];
    }

    /** @return list<array<string, int|null>> */
    private function serializedPricingTiers(): array
    {
        $tiers = [];
        foreach ($this->pricingTiers as $tier) {
            if (! $tier instanceof AiPricingTier) {
                throw new InvalidArgumentException('The AI pricing tiers are invalid.');
            }

            $tiers[] = $tier->toArray();
        }

        return $tiers;
    }

    /** @param array<string, mixed> $data */
    private static function hasAnyRate(array $data): bool
    {
        foreach (['input', 'output', 'cache_read_input', 'cache_write_input', 'reasoning', 'fixed_request'] as $prefix) {
            if ($prefix === 'fixed_request') {
                foreach (['fixed_request_rate_units', 'fixed_request_price', 'fixed_request_cost_minor_units'] as $key) {
                    if (array_key_exists($key, $data)) {
                        return true;
                    }
                }

                continue;
            }

            foreach ([
                "{$prefix}_rate_per_million_units",
                "{$prefix}_price_per_million",
                "{$prefix}_cost_per_million_minor_units",
            ] as $key) {
                if (array_key_exists($key, $data)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $data */
    private static function rate(array $data, string $prefix): ?int
    {
        $isFixed = $prefix === 'fixed_request';
        $exactKey = $isFixed ? 'fixed_request_rate_units' : "{$prefix}_rate_per_million_units";
        $priceKey = $isFixed ? 'fixed_request_price' : "{$prefix}_price_per_million";
        $minorKey = $isFixed ? 'fixed_request_cost_minor_units' : "{$prefix}_cost_per_million_minor_units";

        if (array_key_exists($exactKey, $data)) {
            if ($data[$exactKey] === null) {
                return null;
            }

            return AiMoney::canonicalRateUnits($data[$exactKey], $exactKey);
        }

        if (array_key_exists($priceKey, $data)) {
            if ($data[$priceKey] === null || $data[$priceKey] === '') {
                return null;
            }

            return AiMoney::rateUnitsFromDecimal($data[$priceKey], $priceKey);
        }

        if (array_key_exists($minorKey, $data)) {
            if ($data[$minorKey] === null) {
                return null;
            }

            return AiMoney::rateUnitsFromMinorUnits(
                AiMoney::canonicalMinorUnits($data[$minorKey], $minorKey),
            );
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private static function compatibilityMinor(array $data, string $prefix, ?int $rate): int
    {
        $key = "{$prefix}_cost_per_million_minor_units";
        if (array_key_exists($key, $data) && $data[$key] !== null) {
            return AiMoney::canonicalMinorUnits($data[$key], $key);
        }

        return $rate === null ? 0 : intdiv($rate, self::MICRO_UNITS_PER_MINOR_UNIT);
    }

    /** @param array<string, mixed> $data */
    private static function compatibilityNullableMinor(array $data, string $prefix, ?int $rate): ?int
    {
        $key = "{$prefix}_cost_per_million_minor_units";
        if (array_key_exists($key, $data)) {
            return $data[$key] === null ? null : AiMoney::canonicalMinorUnits($data[$key], $key);
        }

        return $rate === null ? null : intdiv($rate, self::MICRO_UNITS_PER_MINOR_UNIT);
    }

    /** @return list<AiPricingTier> */
    private static function pricingTiers(mixed $data): array
    {
        if ($data === null) {
            return [];
        }

        if (! is_array($data) || ! array_is_list($data)) {
            throw new InvalidArgumentException('The AI pricing tiers are invalid.');
        }

        $tiers = [];
        foreach ($data as $tier) {
            if (! is_array($tier)) {
                throw new InvalidArgumentException('The AI pricing tier is invalid.');
            }

            $minimum = self::nonNegativeInt($tier['minimum_input_tokens'] ?? null, 'minimum_input_tokens');
            $maximum = array_key_exists('maximum_input_tokens', $tier) && $tier['maximum_input_tokens'] !== null
                ? self::nonNegativeInt($tier['maximum_input_tokens'], 'maximum_input_tokens')
                : null;
            $tiers[] = new AiPricingTier(
                minimumInputTokens: $minimum,
                maximumInputTokens: $maximum,
                inputRatePerMillionUnits: self::requiredRate($tier, 'input'),
                outputRatePerMillionUnits: self::requiredRate($tier, 'output'),
                cacheReadRatePerMillionUnits: self::rate($tier, 'cache_read_input'),
                cacheWriteRatePerMillionUnits: self::rate($tier, 'cache_write_input'),
                reasoningRatePerMillionUnits: self::rate($tier, 'reasoning'),
            );
        }

        $previousMaximum = null;
        foreach ($tiers as $tier) {
            if ($previousMaximum !== null && $tier->minimumInputTokens <= $previousMaximum) {
                throw new InvalidArgumentException('The AI pricing tiers overlap.');
            }

            $previousMaximum = $tier->maximumInputTokens;
        }

        return $tiers;
    }

    /** @param array<string, mixed> $data */
    private static function requiredRate(array $data, string $prefix): int
    {
        $rate = self::rate($data, $prefix);
        if ($rate === null) {
            throw new InvalidArgumentException("The AI pricing tier is missing {$prefix} pricing.");
        }

        return $rate;
    }

    private static function currency(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[A-Z]{3}$/', $value) !== 1) {
            throw new InvalidArgumentException('The AI pricing currency is invalid.');
        }

        return $value;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('The AI unsupported meters are invalid.');
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException('The AI unsupported meters are invalid.');
            }

            $items[] = trim($item);
        }

        return array_values(array_unique($items));
    }

    private static function boolean(mixed $value, string $field): bool
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException("The AI pricing {$field} is invalid.");
        }

        return $value;
    }

    private static function nonNegativeInt(mixed $value, string $field): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', $value) === 1) {
            return AiMoney::canonicalRateUnits($value, $field);
        }

        throw new InvalidArgumentException("The AI pricing {$field} is invalid.");
    }

    private static function nullableTimestamp(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("The AI pricing snapshot {$field} is invalid.");
        }

        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            new DateTimeZone((string) config('app.timezone', 'UTC')),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException("The AI pricing snapshot {$field} is invalid.");
        }

        return $value;
    }

    private static function nullableDate(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("The AI pricing snapshot {$field} is invalid.");
        }

        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("The AI pricing snapshot {$field} is invalid.");
        }

        return $value;
    }

    private static function nullableText(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("The AI pricing snapshot {$field} is invalid.");
        }

        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 200) {
            throw new InvalidArgumentException("The AI pricing snapshot {$field} is invalid.");
        }

        return $value;
    }
}
