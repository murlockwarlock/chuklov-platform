<?php

namespace App\Modules\AI\Domain\Registry;

use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\Enums\ModelLifecycleStatus;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use InvalidArgumentException;

final readonly class AiModelDefinition
{
    /**
     * @param  list<string>  $supportedCapabilities
     * @param  list<AiModelModality>  $modalities
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
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
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

        $pricing = null;
        if (is_array($data['pricing'] ?? null) && $data['pricing'] !== []) {
            $pricing = AiPricingSnapshot::fromArray([
                ...$data['pricing'],
                'pricing_source' => AiPricingSnapshot::SOURCE_CATALOG,
            ]);
        }

        return new self(
            provider: $provider,
            modelName: $modelName,
            displayName: $displayName,
            family: $family,
            supportedCapabilities: self::stringList($data['supported_capabilities'] ?? []),
            modalities: array_values($modalities),
            pricing: $pricing,
            lifecycleStatus: $lifecycle,
            catalogSource: self::nullableString($data['catalog_source'] ?? null, 'catalog_source'),
            pricingAsOf: self::nullableString($data['pricing_as_of'] ?? null, 'pricing_as_of'),
        );
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
}
