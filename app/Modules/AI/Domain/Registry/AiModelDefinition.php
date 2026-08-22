<?php

namespace App\Modules\AI\Domain\Registry;

use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\Enums\ModelLifecycleStatus;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final readonly class AiModelDefinition
{
    /**
     * @param  list<string>  $supportedCapabilities
     * @param  list<AiModelModality>  $modalities
     * @param  list<AiPricingPeriod>  $pricingPeriods
     */
    public function __construct(
        public string $provider,
        public string $modelName,
        public string $displayName,
        public string $family,
        public array $supportedCapabilities,
        public array $modalities,
        public ?AiPricingSnapshot $pricing,
        public ModelLifecycleStatus $lifecycleStatus,
        public ?string $catalogSource = null,
        public ?string $pricingAsOf = null,
        public array $pricingPeriods = [],
        public ?AiPricingPeriod $currentPricingPeriod = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?DateTimeInterface $asOf = null): self
    {
        $provider = AiProviderCatalog::normalize($data['provider'] ?? null);
        $modelName = self::requiredString($data['model'] ?? $data['model_name'] ?? null, 'model');
        $displayName = self::requiredString($data['display_name'] ?? null, 'display_name');
        $family = self::requiredString($data['family'] ?? $displayName, 'family');
        $lifecycle = ModelLifecycleStatus::tryFrom((string) ($data['lifecycle'] ?? ModelLifecycleStatus::Active->value));

        if ($lifecycle === null) {
            throw new InvalidArgumentException('The AI model lifecycle is invalid.');
        }

        $modalities = [];
        foreach ((array) ($data['modalities'] ?? []) as $modality) {
            $value = $modality instanceof AiModelModality ? $modality->value : (string) $modality;
            $parsed = AiModelModality::tryFrom($value);

            if ($parsed === null) {
                throw new InvalidArgumentException('The AI model modality is invalid.');
            }

            $modalities[$parsed->value] = $parsed;
        }

        $catalogSource = self::nullableString($data['catalog_source'] ?? null, 'catalog_source');
        $pricingAsOf = self::nullableDate($data['pricing_as_of'] ?? null, 'pricing_as_of');
        $pricingPeriods = self::pricingPeriods($data, $catalogSource, $pricingAsOf);
        AiPricingPeriod::assertNonOverlapping($pricingPeriods);
        $currentPricingPeriod = null;
        $currentAt = $asOf ?? AiPricingPeriod::now();
        foreach ($pricingPeriods as $pricingPeriod) {
            if ($pricingPeriod->contains($currentAt)) {
                $currentPricingPeriod = $pricingPeriod;
                break;
            }
        }
        $pricing = $currentPricingPeriod?->pricing;
        $currentCatalogSource = $currentPricingPeriod instanceof AiPricingPeriod
            ? $currentPricingPeriod->catalogSource
            : $catalogSource;
        $currentPricingAsOf = $currentPricingPeriod instanceof AiPricingPeriod
            ? $currentPricingPeriod->pricingAsOf
            : $pricingAsOf;

        return new self(
            provider: $provider,
            modelName: $modelName,
            displayName: $displayName,
            family: $family,
            supportedCapabilities: self::stringList($data['supported_capabilities'] ?? []),
            modalities: array_values($modalities),
            pricing: $pricing,
            lifecycleStatus: $lifecycle,
            pricingPeriods: $pricingPeriods,
            currentPricingPeriod: $currentPricingPeriod,
            catalogSource: $currentCatalogSource,
            pricingAsOf: $currentPricingAsOf,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<AiPricingPeriod>
     */
    private static function pricingPeriods(
        array $data,
        ?string $catalogSource,
        ?string $pricingAsOf,
    ): array {
        if (array_key_exists('pricing_periods', $data)) {
            if (! is_array($data['pricing_periods']) || ! array_is_list($data['pricing_periods'])) {
                throw new InvalidArgumentException('The AI model pricing periods are invalid.');
            }

            if (is_array($data['pricing'] ?? null) && $data['pricing'] !== []) {
                throw new InvalidArgumentException('The AI model must use either pricing or pricing_periods.');
            }

            return array_map(
                static function (mixed $period) use ($catalogSource, $pricingAsOf): AiPricingPeriod {
                    if (! is_array($period)) {
                        throw new InvalidArgumentException('The AI pricing period is invalid.');
                    }

                    return AiPricingPeriod::fromArray($period, $catalogSource, $pricingAsOf);
                },
                $data['pricing_periods'],
            );
        }

        if (! is_array($data['pricing'] ?? null) || $data['pricing'] === []) {
            return [];
        }

        return [AiPricingPeriod::fromPricing($data['pricing'], $catalogSource, $pricingAsOf)];
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), (array) $value),
            static fn (string $item): bool => $item !== '',
        )));
    }

    private static function requiredString(mixed $value, string $field): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("The AI model {$field} is invalid.");
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 120) {
            throw new InvalidArgumentException("The AI model {$field} is invalid.");
        }

        return $value;
    }

    private static function nullableString(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::requiredString($value, $field);
    }

    private static function nullableDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("The AI model {$field} is invalid.");
        }

        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("The AI model {$field} is invalid.");
        }

        return $value;
    }
}
