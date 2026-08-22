<?php

namespace App\Modules\AI\Domain\Registry;

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

        foreach ([
            ['input_cost_per_million_minor_units', 'input_price_per_million'],
            ['output_cost_per_million_minor_units', 'output_price_per_million'],
        ] as $priceKeys) {
            $hasPrice = false;
            foreach ($priceKeys as $priceKey) {
                if (array_key_exists($priceKey, $pricing)
                    && $pricing[$priceKey] !== null
                    && $pricing[$priceKey] !== '') {
                    $hasPrice = true;
                    break;
                }
            }

            if (! $hasPrice) {
                throw new InvalidArgumentException('The AI pricing period must define input and output prices.');
            }
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
            ...$pricing,
            'pricing_source' => AiPricingSnapshot::SOURCE_CATALOG,
            'catalog_pricing_effective_from' => $effectiveFrom?->format(self::DATE_TIME_FORMAT),
            'catalog_pricing_effective_until' => $effectiveUntil?->format(self::DATE_TIME_FORMAT),
            'catalog_pricing_as_of' => $pricingAsOf,
        ]);

        return new self(
            effectiveFrom: $effectiveFrom,
            effectiveUntil: $effectiveUntil,
            pricing: $snapshot,
            catalogSource: $catalogSource,
            pricingAsOf: $pricingAsOf,
        );
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
