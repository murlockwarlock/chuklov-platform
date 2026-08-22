<?php

namespace App\Modules\AI\Application\Data;

use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Registry\AiModelCatalog;
use App\Modules\AI\Domain\Registry\AiModelDefinition;
use App\Modules\AI\Domain\ValueObjects\AiMoney;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use InvalidArgumentException;

final readonly class AiModelConfigurationInput
{
    /**
     * @param  list<string>  $capabilities
     */
    public function __construct(
        public string $modelName,
        public string $displayName,
        public array $capabilities,
        public AiPricingSnapshot $pricing,
        public int $failoverPriority,
        public bool $isEnabled,
    ) {}

    /** @param array<string, mixed> $data */
    public static function forCreate(AiProviderConfiguration $provider, array $data): self
    {
        return self::fromData(
            provider: $provider->provider_name,
            data: $data,
            existing: null,
            defaultEnabled: false,
        );
    }

    /** @param array<string, mixed> $data */
    public static function forRelease(AiModelConfiguration $model, array $data): self
    {
        $provider = $model->providerConfiguration;
        if ($provider === null) {
            throw new InvalidArgumentException('The model provider configuration is missing.');
        }

        return self::fromData(
            provider: $provider->provider_name,
            data: $data,
            existing: $model,
            defaultEnabled: true,
        );
    }

    /** @param array<string, mixed> $data */
    private static function fromData(
        string $provider,
        array $data,
        ?AiModelConfiguration $existing,
        bool $defaultEnabled,
    ): self {
        $selection = $data['model_selection'] ?? null;
        $definition = AiModelCatalog::selectedDefinition($provider, $selection);
        $existingPricing = $existing?->getPricingSnapshot();
        $explicitCustomSelection = array_key_exists('model_selection', $data)
            && self::isCustomSelection($selection);
        $existingCatalogDefinition = $existing === null
            ? null
            : AiModelCatalog::find($provider, $existing->model_name);
        $discardExistingCatalogMetadata = $explicitCustomSelection
            && $existingCatalogDefinition !== null;
        $existingModelName = $existing === null ? null : $existing->model_name;
        $existingDisplayName = $discardExistingCatalogMetadata || $existing === null
            ? null
            : $existing->display_name;
        $modelName = $definition !== null
            ? $definition->modelName
            : self::modelName($data['model_name'] ?? $existingModelName);
        $catalogDisplayName = $definition === null ? null : $definition->displayName;
        $displayName = self::displayName($data['display_name'] ?? $existingDisplayName ?? $catalogDisplayName);
        $capabilities = self::capabilities($data, $existing, $definition, $discardExistingCatalogMetadata);
        $pricing = self::pricing($data, $existingPricing, $definition?->pricing, $discardExistingCatalogMetadata);

        return new self(
            modelName: $modelName,
            displayName: $displayName,
            capabilities: $capabilities,
            pricing: $pricing,
            failoverPriority: self::positiveInteger($data['failover_priority'] ?? ($existing === null ? 1 : $existing->failover_priority)),
            isEnabled: array_key_exists('is_enabled', $data)
                ? (bool) $data['is_enabled']
                : $defaultEnabled,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function capabilities(
        array $data,
        ?AiModelConfiguration $existing,
        ?AiModelDefinition $definition,
        bool $discardExistingCatalogMetadata,
    ): array {
        $capabilities = array_key_exists('capabilities', $data)
            ? self::stringList($data['capabilities'])
            : self::stringList($existing === null ? [] : $existing->capabilities);
        $allModalityValues = array_map(
            static fn (AiModelModality $modality): string => $modality->value,
            AiModelModality::cases(),
        );
        if ($definition !== null) {
            $capabilities = array_values(array_diff($capabilities, $allModalityValues));
            $modalityValues = array_map(
                static fn (AiModelModality $modality): string => $modality->value,
                $definition->modalities,
            );
        } elseif (array_key_exists('model_modalities', $data)) {
            if ($discardExistingCatalogMetadata) {
                $capabilities = array_values(array_diff($capabilities, $allModalityValues));
            }

            $modalityValues = self::stringList($data['model_modalities']);
        } elseif ($discardExistingCatalogMetadata) {
            $capabilities = array_values(array_diff($capabilities, $allModalityValues));
            $modalityValues = [];
        } else {
            $modalityValues = array_values(array_intersect(
                self::stringList($existing === null ? [] : $existing->capabilities),
                $allModalityValues,
            ));
        }

        return array_values(array_unique([...$capabilities, ...$modalityValues]));
    }

    /** @param array<string, mixed> $data */
    private static function pricing(
        array $data,
        ?AiPricingSnapshot $existing,
        ?AiPricingSnapshot $catalogPricing,
        bool $discardExistingCatalogMetadata,
    ): AiPricingSnapshot {
        if (array_key_exists('pricing_snapshot', $data)) {
            return self::directPricing($data['pricing_snapshot']);
        }

        $base = $catalogPricing ?? ($discardExistingCatalogMetadata ? null : $existing);
        $priceFields = [
            'input_cost_per_million',
            'output_cost_per_million',
            'cache_read_input_cost_per_million',
            'cache_write_input_cost_per_million',
            'reasoning_cost_per_million',
            'fixed_request_cost_minor_units',
            'unsupported_meters',
        ];
        $hasManualInput = false;
        foreach ($priceFields as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                $hasManualInput = true;
                break;
            }
        }
        if (($data['fixed_request_cost_applicable'] ?? false) === true) {
            $hasManualInput = true;
        }

        if ($base === null && ! $hasManualInput) {
            return new AiPricingSnapshot(
                cacheReadInputCostPerMillionMinorUnits: null,
                cacheWriteInputCostPerMillionMinorUnits: null,
                reasoningCostPerMillionMinorUnits: null,
                fixedRequestCostMinorUnits: null,
                pricingSource: AiPricingSnapshot::SOURCE_UNKNOWN,
            );
        }

        if ($base === null) {
            $base = new AiPricingSnapshot(
                cacheReadInputCostPerMillionMinorUnits: null,
                cacheWriteInputCostPerMillionMinorUnits: null,
                reasoningCostPerMillionMinorUnits: null,
                fixedRequestCostMinorUnits: null,
                pricingSource: AiPricingSnapshot::SOURCE_UNKNOWN,
            );
        }

        if (! $hasManualInput) {
            return $base;
        }
        $pricing = new AiPricingSnapshot(
            currency: $base->currency,
            inputCostPerMillionMinorUnits: self::requiredMinor(
                $data,
                'input_cost_per_million',
                $base->pricingSource === AiPricingSnapshot::SOURCE_UNKNOWN ? null : $base->inputCostPerMillionMinorUnits,
            ),
            outputCostPerMillionMinorUnits: self::requiredMinor(
                $data,
                'output_cost_per_million',
                $base->pricingSource === AiPricingSnapshot::SOURCE_UNKNOWN ? null : $base->outputCostPerMillionMinorUnits,
            ),
            cacheReadInputCostPerMillionMinorUnits: self::optionalMinor($data, 'cache_read_input_cost_per_million', $base->cacheReadInputCostPerMillionMinorUnits),
            cacheWriteInputCostPerMillionMinorUnits: self::optionalMinor($data, 'cache_write_input_cost_per_million', $base->cacheWriteInputCostPerMillionMinorUnits),
            reasoningCostPerMillionMinorUnits: self::optionalMinor($data, 'reasoning_cost_per_million', $base->reasoningCostPerMillionMinorUnits),
            fixedRequestCostApplicable: array_key_exists('fixed_request_cost_applicable', $data)
                ? (bool) $data['fixed_request_cost_applicable']
                : $base->fixedRequestCostApplicable,
            fixedRequestCostMinorUnits: self::optionalMinor($data, 'fixed_request_cost_minor_units', $base->fixedRequestCostMinorUnits),
            unsupportedMeters: array_key_exists('unsupported_meters', $data)
                ? self::unsupportedMeters($data['unsupported_meters'])
                : $base->unsupportedMeters,
            pricingSource: AiPricingSnapshot::SOURCE_MANUAL,
        );

        if ($catalogPricing !== null
            && $existing !== null
            && $existing->pricingSource === AiPricingSnapshot::SOURCE_CATALOG
            && $pricing->sameBillablePricing($existing)) {
            return $catalogPricing;
        }

        if ($catalogPricing !== null && $pricing->sameBillablePricing($catalogPricing)) {
            return $catalogPricing;
        }

        return $pricing;
    }

    private static function directPricing(mixed $value): AiPricingSnapshot
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('The pricing snapshot must be a canonical array.');
        }

        foreach ([
            'input_cost_per_million_minor_units',
            'output_cost_per_million_minor_units',
            'cache_read_input_cost_per_million_minor_units',
            'cache_write_input_cost_per_million_minor_units',
            'reasoning_cost_per_million_minor_units',
        ] as $key) {
            if (! array_key_exists($key, $value)) {
                throw new InvalidArgumentException("The pricing snapshot is missing {$key}.");
            }

            $value[$key] = AiMoney::canonicalMinorUnits($value[$key], $key);
        }

        if (array_key_exists('fixed_request_cost_minor_units', $value) && $value['fixed_request_cost_minor_units'] !== null) {
            $value['fixed_request_cost_minor_units'] = AiMoney::canonicalMinorUnits(
                $value['fixed_request_cost_minor_units'],
                'fixed_request_cost_minor_units',
            );
        }

        return AiPricingSnapshot::fromArray([
            ...$value,
            'pricing_source' => AiPricingSnapshot::SOURCE_MANUAL,
        ]);
    }

    private static function modelName(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 120) {
            throw new InvalidArgumentException('Укажите модель или выберите её из каталога.');
        }

        return trim($value);
    }

    private static function isCustomSelection(mixed $selection): bool
    {
        return $selection === null || $selection === '' || $selection === AiModelCatalog::CUSTOM_MODEL;
    }

    private static function displayName(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 120) {
            throw new InvalidArgumentException('Название модели в CRM обязательно.');
        }

        return trim($value);
    }

    /** @param array<string, mixed> $data */
    private static function requiredMinor(array $data, string $key, ?int $default): int
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            if ($default === null) {
                throw new InvalidArgumentException('Укажите стоимость входных данных и ответа модели.');
            }

            return $default;
        }

        return self::minor($data[$key], $key);
    }

    /** @param array<string, mixed> $data */
    private static function optionalMinor(array $data, string $key, ?int $default): ?int
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return $default;
        }

        return self::minor($data[$key], $key);
    }

    private static function minor(mixed $value, string $key): int
    {
        return is_int($value)
            ? AiMoney::canonicalMinorUnits($value, $key)
            : AiMoney::minorUnitsFromDecimal($value);
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), (array) $value),
            static fn (string $item): bool => $item !== '',
        )));
    }

    /** @return list<string> */
    private static function unsupportedMeters(mixed $value): array
    {
        $values = is_array($value)
            ? $value
            : (is_string($value) ? preg_split('/\\s*,\\s*/', $value) ?: [] : []);

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $meter): string => trim((string) $meter), $values),
            static fn (string $meter): bool => $meter !== '',
        )));
    }

    private static function positiveInteger(mixed $value): int
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            if ($value > PHP_INT_MAX) {
                throw new InvalidArgumentException('failover_priority is outside the supported range.');
            }

            $value = (int) $value;
        }

        $value = AiMoney::canonicalMinorUnits($value, 'failover_priority');

        if ($value < 1) {
            throw new InvalidArgumentException('failover_priority must be at least 1.');
        }

        return $value;
    }
}
