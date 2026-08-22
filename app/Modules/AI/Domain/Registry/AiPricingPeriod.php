<?php

namespace App\Modules\AI\Domain\Registry;

use App\Modules\AI\Domain\ValueObjects\AiMoney;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final readonly class AiPricingPeriod
{
    public const string DATE_TIME_FORMAT = 'Y-m-d H:i:s';

    public const string DATE_FORMAT = 'Y-m-d';

    public function __construct(
        public ?CarbonImmutable $effectiveFrom,
        public ?CarbonImmutable $effectiveUntil,
        public AiPricingSnapshot $pricing,
        public ?string $catalogSource = null,
        public ?string $pricingAsOf = null,
    ) {
        if ($this->effectiveFrom !== null
            && $this->effectiveUntil !== null
            && $this->effectiveFrom->greaterThan($this->effectiveUntil)) {
            throw new InvalidArgumentException('The AI pricing period has an invalid range.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(
        array $data,
        ?string $defaultCatalogSource = null,
        ?string $defaultPricingAsOf = null,
    ): self {
        $pricing = $data['pricing'] ?? null;
        if (! is_array($pricing) || $pricing === []) {
            throw new InvalidArgumentException('The AI pricing period must define pricing.');
        }

        $effectiveFrom = self::parseBoundary($data['effective_from'] ?? null, 'effective_from');
        $effectiveUntil = self::parseBoundary($data['effective_until'] ?? null, 'effective_until');
        $catalogSource = self::optionalText(
            $data['catalog_source'] ?? $defaultCatalogSource,
            'catalog_source',
        );
        $pricingAsOf = self::parseDate(
            $data['pricing_as_of'] ?? $defaultPricingAsOf,
            'pricing_as_of',
        );

        $snapshot = AiPricingSnapshot::fromArray([
            ...self::normalizePricing($pricing),
            'pricing_source' => AiPricingSnapshot::SOURCE_CATALOG,
            'catalog_pricing_effective_from' => $effectiveFrom?->format(self::DATE_TIME_FORMAT),
            'catalog_pricing_effective_until' => $effectiveUntil?->format(self::DATE_TIME_FORMAT),
            'catalog_pricing_as_of' => $pricingAsOf,
            'catalog_source' => $catalogSource,
        ]);

        return new self(
            effectiveFrom: $effectiveFrom,
            effectiveUntil: $effectiveUntil,
            pricing: $snapshot,
            catalogSource: $catalogSource,
            pricingAsOf: $pricingAsOf,
        );
    }

    /**
     * @param  array<string, mixed>  $pricing
     * @return array<string, mixed>
     */
    private static function normalizePricing(array $pricing): array
    {
        $normalized = $pricing;

        foreach (['input', 'output'] as $prefix) {
            $rateKey = "{$prefix}_rate_per_million_units";
            $priceKey = "{$prefix}_price_per_million";
            $minorKey = "{$prefix}_cost_per_million_minor_units";

            if (array_key_exists($rateKey, $pricing)) {
                $normalized[$rateKey] = AiMoney::canonicalRateUnits($pricing[$rateKey], $rateKey);

                continue;
            }

            if (array_key_exists($priceKey, $pricing)) {
                if ($pricing[$priceKey] === null || $pricing[$priceKey] === '') {
                    throw new InvalidArgumentException('The AI pricing period must define input and output prices.');
                }

                $normalized[$rateKey] = AiMoney::rateUnitsFromDecimal($pricing[$priceKey], $priceKey);
                unset($normalized[$priceKey]);

                continue;
            }

            if (! array_key_exists($minorKey, $pricing)
                || $pricing[$minorKey] === null
                || $pricing[$minorKey] === '') {
                throw new InvalidArgumentException('The AI pricing period must define input and output prices.');
            }

            $normalized[$minorKey] = AiMoney::canonicalMinorUnits($pricing[$minorKey], $minorKey);
        }

        foreach (['cache_read_input', 'cache_write_input', 'reasoning'] as $prefix) {
            $rateKey = "{$prefix}_rate_per_million_units";
            $priceKey = "{$prefix}_price_per_million";
            $minorKey = "{$prefix}_cost_per_million_minor_units";

            if (array_key_exists($rateKey, $pricing)) {
                $normalized[$rateKey] = $pricing[$rateKey] === null
                    ? null
                    : AiMoney::canonicalRateUnits($pricing[$rateKey], $rateKey);
            } elseif (array_key_exists($priceKey, $pricing)) {
                $normalized[$rateKey] = $pricing[$priceKey] === null
                    ? null
                    : AiMoney::rateUnitsFromDecimal($pricing[$priceKey], $priceKey);
                unset($normalized[$priceKey]);
            } elseif (array_key_exists($minorKey, $pricing) && $pricing[$minorKey] !== null) {
                $normalized[$minorKey] = AiMoney::canonicalMinorUnits($pricing[$minorKey], $minorKey);
            }
        }

        if (array_key_exists('fixed_request_rate_units', $pricing)) {
            $normalized['fixed_request_rate_units'] = $pricing['fixed_request_rate_units'] === null
                ? null
                : AiMoney::canonicalRateUnits($pricing['fixed_request_rate_units'], 'fixed_request_rate_units');
        } elseif (array_key_exists('fixed_request_price', $pricing)) {
            $normalized['fixed_request_rate_units'] = $pricing['fixed_request_price'] === null
                ? null
                : AiMoney::rateUnitsFromDecimal($pricing['fixed_request_price'], 'fixed_request_price');
            unset($normalized['fixed_request_price']);
        } elseif (array_key_exists('fixed_request_cost_minor_units', $pricing)
            && $pricing['fixed_request_cost_minor_units'] !== null) {
            $normalized['fixed_request_cost_minor_units'] = AiMoney::canonicalMinorUnits(
                $pricing['fixed_request_cost_minor_units'],
                'fixed_request_cost_minor_units',
            );
        }

        return $normalized;
    }

    /** @param array<string, mixed> $pricing */
    public static function fromPricing(
        array $pricing,
        ?string $catalogSource = null,
        ?string $pricingAsOf = null,
    ): self {
        return self::fromArray([
            'pricing' => $pricing,
            'catalog_source' => $catalogSource,
            'pricing_as_of' => $pricingAsOf,
        ]);
    }

    public function contains(DateTimeInterface $at): bool
    {
        return ($this->effectiveFrom === null || $at >= $this->effectiveFrom)
            && ($this->effectiveUntil === null || $at <= $this->effectiveUntil);
    }

    /** @param list<self> $periods */
    public static function assertNonOverlapping(array $periods): void
    {
        foreach ($periods as $index => $period) {
            foreach (array_slice($periods, $index + 1) as $other) {
                if (self::overlaps($period, $other)) {
                    throw new InvalidArgumentException('AI pricing periods must not overlap.');
                }
            }
        }
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.timezone', 'UTC'))->setMicrosecond(0);
    }

    private static function overlaps(self $left, self $right): bool
    {
        return ($left->effectiveUntil === null || $right->effectiveFrom === null || $left->effectiveUntil >= $right->effectiveFrom)
            && ($right->effectiveUntil === null || $left->effectiveFrom === null || $right->effectiveUntil >= $left->effectiveFrom);
    }

    private static function parseBoundary(mixed $value, string $field): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("The AI pricing period {$field} is invalid.");
        }

        $value = trim($value);
        $date = CarbonImmutable::createFromFormat(
            '!'.self::DATE_TIME_FORMAT,
            $value,
            new DateTimeZone((string) config('app.timezone', 'UTC')),
        );
        $errors = CarbonImmutable::getLastErrors();

        if ($date === null
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format(self::DATE_TIME_FORMAT) !== $value) {
            throw new InvalidArgumentException("The AI pricing period {$field} is invalid.");
        }

        return $date->setMicrosecond(0);
    }

    private static function parseDate(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("The AI pricing period {$field} is invalid.");
        }

        $value = trim($value);
        $date = CarbonImmutable::createFromFormat(
            '!'.self::DATE_FORMAT,
            $value,
            new DateTimeZone('UTC'),
        );
        $errors = CarbonImmutable::getLastErrors();

        if ($date === null
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format(self::DATE_FORMAT) !== $value) {
            throw new InvalidArgumentException("The AI pricing period {$field} is invalid.");
        }

        return $value;
    }

    private static function optionalText(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("The AI pricing period {$field} is invalid.");
        }

        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 200) {
            throw new InvalidArgumentException("The AI pricing period {$field} is invalid.");
        }

        return $value;
    }
}
