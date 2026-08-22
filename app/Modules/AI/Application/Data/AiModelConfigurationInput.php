<?php

namespace App\Modules\AI\Application\Data;

use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Registry\AiModelCatalog;
use App\Modules\AI\Domain\Registry\AiModelDefinition;
use App\Modules\AI\Domain\Registry\AiProviderCatalog;
use App\Modules\AI\Domain\ValueObjects\AiMoney;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use BackedEnum;
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
    public static function forCreate(
        AiProviderConfiguration $provider,
        array $data,
        ?AiModelDefinition $discoveredDefinition = null,
    ): self {
        return self::fromData(
            provider: $provider->provider_name,
            data: $data,
            existing: null,
            defaultEnabled: false,
            discoveredDefinition: $discoveredDefinition,
        );
    }

    /** @param array<string, mixed> $data */
    public static function forRelease(
        AiModelConfiguration $model,
        array $data,
        ?AiModelDefinition $discoveredDefinition = null,
    ): self {
        $provider = $model->providerConfiguration;
        if ($provider === null) {
            throw new InvalidArgumentException('The model provider configuration is missing.');
        }

        return self::fromData(
            provider: $provider->provider_name,
            data: $data,
            existing: $model,
            defaultEnabled: true,
            discoveredDefinition: $discoveredDefinition,
        );
    }

    /** @param array<string, mixed> $data */
    private static function fromData(
        string $provider,
        array $data,
        ?AiModelConfiguration $existing,
        bool $defaultEnabled,
        ?AiModelDefinition $discoveredDefinition,
    ): self {
        $selectionProvided = array_key_exists('model_selection', $data);
        $selection = $data['model_selection'] ?? null;
        $existingPricing = $existing?->getPricingSnapshot();
        $persistedDiscoveredDefinition = self::persistedDiscoveredDefinition(
            provider: $provider,
            selection: $selectionProvided ? $selection : ($data['model_name'] ?? $existing?->model_name),
            existing: $existing,
            pricing: $existingPricing,
        );
        try {
            $selectedDefinition = AiModelCatalog::selectedDefinition($provider, $selection);
        } catch (InvalidArgumentException $exception) {
            if (self::matchesDiscoveredDefinition($provider, $selection, $discoveredDefinition)) {
                $selectedDefinition = $discoveredDefinition;
            } elseif (self::matchesDiscoveredDefinition($provider, $selection, $persistedDiscoveredDefinition)) {
                $selectedDefinition = $persistedDiscoveredDefinition;
            } else {
                throw $exception;
            }
        }
        if ($selectedDefinition === null && $persistedDiscoveredDefinition !== null) {
            $selectedDefinition = $persistedDiscoveredDefinition;
        }
        $existingCatalogDefinition = $existing === null
            ? null
            : AiModelCatalog::find($provider, $existing->model_name);
        $existingModelName = $existing === null ? null : $existing->model_name;
        $requestedModelName = ! $selectionProvided && array_key_exists('model_name', $data)
            ? self::nullableModelName($data['model_name'])
            : null;
        $useExistingCatalogDefinition = $existingCatalogDefinition !== null
            && ! $selectionProvided
            && ($requestedModelName === null || $requestedModelName === $existingModelName);
        $definition = $useExistingCatalogDefinition
            ? $existingCatalogDefinition
            : $selectedDefinition;
        $modelName = $definition !== null
            ? $definition->modelName
            : self::modelName($data['model_name'] ?? $existingModelName);
        if ($definition === null && AiModelCatalog::find($provider, $modelName) !== null) {
            throw new InvalidArgumentException('Выберите каталожную модель вместо ручной модели с таким же идентификатором.');
        }

        if ($definition !== null
            && ! $definition->lifecycleStatus->isSelectableForNewConfiguration()
            && ($existing === null || $existingModelName !== $definition->modelName)) {
            throw new InvalidArgumentException('Выбранная модель больше недоступна для новых конфигураций.');
        }

        $sameManualIdentity = $existing !== null
            && $existingCatalogDefinition === null
            && $definition === null
            && $existingModelName === $modelName;
        $explicitCustomSelection = $selectionProvided
            && ($selection === null || $selection === '' || $selection === AiModelCatalog::CUSTOM_MODEL);
        $resetExistingCustomState = $existing !== null
            && $definition === null
            && (! $sameManualIdentity
                || ($explicitCustomSelection
                    && $existingPricing?->pricingSource === AiPricingSnapshot::SOURCE_CATALOG));
        $definitionAuthoritative = $definition !== null;
        $catalogPricingAuthoritative = $definition?->pricing !== null;
        $catalogDisplayName = $definition === null ? null : $definition->displayName;
        $existingDisplayName = $existing === null ? null : $existing->display_name;
        $displayName = self::displayName($data['display_name'] ?? $existingDisplayName ?? $catalogDisplayName);
        $capabilities = self::capabilities(
            provider: $provider,
            data: $data,
            existing: $existing,
            definition: $definition,
            catalogAuthoritative: $definitionAuthoritative,
            resetExistingCustomState: $resetExistingCustomState,
        );
        $pricing = self::pricing(
            data: $data,
            existing: $existingPricing,
            catalogPricing: $definition?->pricing,
            catalogAuthoritative: $catalogPricingAuthoritative,
            resetExistingCustomState: $resetExistingCustomState,
        );

        return new self(
            modelName: $modelName,
            displayName: $displayName,
            capabilities: $capabilities,
            pricing: $pricing,
            failoverPriority: self::positiveInteger($data['failover_priority'] ?? ($existing === null ? 1 : $existing->failover_priority)),
            isEnabled: array_key_exists('is_enabled', $data)
                ? self::boolean($data['is_enabled'], 'is_enabled')
                : ($existing === null ? $defaultEnabled : $existing->is_enabled),
        );
    }

    private static function matchesDiscoveredDefinition(
        string $provider,
        mixed $selection,
        ?AiModelDefinition $definition,
    ): bool {
        if (! $definition instanceof AiModelDefinition
            || ! is_string($selection)
            || trim($selection) === ''
            || $selection === AiModelCatalog::CUSTOM_MODEL) {
            return false;
        }

        try {
            $provider = AiProviderCatalog::normalize($provider);
        } catch (InvalidArgumentException) {
            return false;
        }

        return $definition->provider === $provider && $definition->modelName === trim($selection);
    }

    private static function persistedDiscoveredDefinition(
        string $provider,
        mixed $selection,
        ?AiModelConfiguration $existing,
        ?AiPricingSnapshot $pricing,
    ): ?AiModelDefinition {
        if (! $existing instanceof AiModelConfiguration
            || ! $pricing instanceof AiPricingSnapshot
            || $pricing->pricingSource !== AiPricingSnapshot::SOURCE_CATALOG
            || ! AiModelCatalog::isImmutableDiscoveredPricing($provider, $pricing)
            || ! is_string($selection)
            || trim($selection) === ''
            || trim($selection) !== $existing->model_name
            || AiModelCatalog::find($provider, $existing->model_name) !== null) {
            return null;
        }

        $modalityValues = array_values(array_intersect(
            $existing->capabilities,
            array_map(
                static fn (AiModelModality $modality): string => $modality->value,
                AiModelModality::cases(),
            ),
        ));

        return AiModelDefinition::fromArray([
            'provider' => $provider,
            'model' => $existing->model_name,
            'display_name' => $existing->display_name,
            'family' => 'Сохранённая модель',
            'summary' => 'Модель сохранена из подключённого каталога; её текущая запись провайдера временно недоступна.',
            'positioning' => 'Ранее обнаруженная',
            'recommended' => false,
            'supported_capabilities' => ['text_generation'],
            'modalities' => $modalityValues,
            'pricing' => $pricing->toArray(),
            'lifecycle' => 'active',
            'catalog_source' => $pricing->catalogSource,
            'pricing_as_of' => $pricing->catalogPricingAsOf,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function capabilities(
        string $provider,
        array $data,
        ?AiModelConfiguration $existing,
        ?AiModelDefinition $definition,
        bool $catalogAuthoritative,
        bool $resetExistingCustomState,
    ): array {
        $capabilities = array_key_exists('capabilities', $data)
            ? self::capabilityList($data['capabilities'])
            : self::capabilityList($existing === null ? [] : $existing->capabilities);
        $allModalityValues = array_map(
            static fn (AiModelModality $modality): string => $modality->value,
            AiModelModality::cases(),
        );
        if ($catalogAuthoritative && $definition !== null) {
            $modalityValues = array_map(
                static fn (AiModelModality $modality): string => $modality->value,
                $definition->modalities,
            );
        } elseif (array_key_exists('model_modalities', $data)) {
            $modalityValues = self::modalityList($data['model_modalities']);
            $unsupportedModalities = array_diff($modalityValues, AiProviderCatalog::modalities($provider));
            if ($unsupportedModalities !== []) {
                throw new InvalidArgumentException('Выбранный тип входных данных не поддерживается адаптером провайдера.');
            }
        } elseif ($resetExistingCustomState) {
            $modalityValues = [];
        } else {
            $modalityValues = array_values(array_intersect(
                self::stringList($existing === null ? [] : $existing->capabilities, 'capabilities'),
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
        bool $catalogAuthoritative,
        bool $resetExistingCustomState,
    ): AiPricingSnapshot {
        if ($catalogAuthoritative) {
            return $catalogPricing ?? self::unknownPricing();
        }

        if (array_key_exists('pricing_snapshot', $data)) {
            return self::directPricing($data['pricing_snapshot']);
        }

        $base = $resetExistingCustomState || ! $existing instanceof AiPricingSnapshot
            ? null
            : $existing;
        $priceFields = [
            'input_cost_per_million',
            'output_cost_per_million',
            'cache_read_input_cost_per_million',
            'cache_write_input_cost_per_million',
            'reasoning_cost_per_million',
            'fixed_request_cost_minor_units',
        ];
        $hasManualInput = false;
        foreach ($priceFields as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                $hasManualInput = true;
                break;
            }
        }
        $fixedRequestCostApplicable = array_key_exists('fixed_request_cost_applicable', $data)
            && $data['fixed_request_cost_applicable'] !== null
            ? self::boolean($data['fixed_request_cost_applicable'], 'fixed_request_cost_applicable')
            : null;
        if ($fixedRequestCostApplicable !== null
            && (($base === null && $fixedRequestCostApplicable)
                || ($base !== null && $fixedRequestCostApplicable !== $base->fixedRequestCostApplicable))) {
            $hasManualInput = true;
        }
        $unsupportedMeters = array_key_exists('unsupported_meters', $data)
            ? self::unsupportedMeters($data['unsupported_meters'])
            : null;
        if ($unsupportedMeters !== null
            && (($base === null && $unsupportedMeters !== [])
                || ($base !== null && $unsupportedMeters !== $base->unsupportedMeters))) {
            $hasManualInput = true;
        }

        if ($base === null && ! $hasManualInput) {
            return self::unknownPricing();
        }

        if ($base === null) {
            $base = self::unknownPricing();
        }

        if (! $hasManualInput) {
            return $base;
        }
        $inputRate = self::requiredRate(
            $data,
            'input_cost_per_million',
            $base->pricingSource === AiPricingSnapshot::SOURCE_UNKNOWN ? null : $base->inputRatePerMillionUnits(),
        );
        $outputRate = self::requiredRate(
            $data,
            'output_cost_per_million',
            $base->pricingSource === AiPricingSnapshot::SOURCE_UNKNOWN ? null : $base->outputRatePerMillionUnits(),
        );
        $cacheReadRate = self::optionalRate(
            $data,
            'cache_read_input_cost_per_million',
            $base->pricingSource === AiPricingSnapshot::SOURCE_UNKNOWN ? null : $base->cacheReadRatePerMillionUnits(),
        );
        $cacheWriteRate = self::optionalRate(
            $data,
            'cache_write_input_cost_per_million',
            $base->pricingSource === AiPricingSnapshot::SOURCE_UNKNOWN ? null : $base->cacheWriteRatePerMillionUnits(),
        );
        $reasoningRate = self::optionalRate(
            $data,
            'reasoning_cost_per_million',
            $base->pricingSource === AiPricingSnapshot::SOURCE_UNKNOWN ? null : $base->reasoningRatePerMillionUnits(),
        );
        $fixedRequestRate = self::optionalRate(
            $data,
            'fixed_request_cost_minor_units',
            $base->fixedRequestRateUnits(),
        );
        $pricing = new AiPricingSnapshot(
            currency: $base->currency,
            inputCostPerMillionMinorUnits: self::compatibilityMinor($inputRate),
            outputCostPerMillionMinorUnits: self::compatibilityMinor($outputRate),
            cacheReadInputCostPerMillionMinorUnits: self::compatibilityNullableMinor($cacheReadRate),
            cacheWriteInputCostPerMillionMinorUnits: self::compatibilityNullableMinor($cacheWriteRate),
            reasoningCostPerMillionMinorUnits: self::compatibilityNullableMinor($reasoningRate),
            fixedRequestCostApplicable: $fixedRequestCostApplicable ?? $base->fixedRequestCostApplicable,
            fixedRequestCostMinorUnits: self::compatibilityNullableMinor($fixedRequestRate),
            unsupportedMeters: $unsupportedMeters ?? $base->unsupportedMeters,
            pricingSource: AiPricingSnapshot::SOURCE_MANUAL,
            inputRatePerMillionUnits: $inputRate,
            outputRatePerMillionUnits: $outputRate,
            cacheReadRatePerMillionUnits: $cacheReadRate,
            cacheWriteRatePerMillionUnits: $cacheWriteRate,
            reasoningRatePerMillionUnits: $reasoningRate,
            fixedRequestRateUnits: $fixedRequestRate,
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

        $exactKeys = [
            'input_rate_per_million_units',
            'output_rate_per_million_units',
            'cache_read_input_rate_per_million_units',
            'cache_write_input_rate_per_million_units',
            'reasoning_rate_per_million_units',
            'fixed_request_rate_units',
            'input_price_per_million',
            'output_price_per_million',
            'cache_read_input_price_per_million',
            'cache_write_input_price_per_million',
            'reasoning_price_per_million',
            'fixed_request_price',
            'pricing_tiers',
        ];
        if (array_intersect($exactKeys, array_keys($value)) !== []) {
            return AiPricingSnapshot::fromArray([
                ...$value,
                'pricing_source' => AiPricingSnapshot::SOURCE_MANUAL,
            ]);
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

        if (array_key_exists('fixed_request_cost_applicable', $value)
            && ! is_bool($value['fixed_request_cost_applicable'])) {
            throw new InvalidArgumentException('fixed_request_cost_applicable must be a boolean.');
        }

        if (array_key_exists('unsupported_meters', $value)) {
            $value['unsupported_meters'] = self::unsupportedMeters($value['unsupported_meters']);
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

    private static function nullableModelName(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 120) {
            throw new InvalidArgumentException('Укажите модель или выберите её из каталога.');
        }

        return trim($value);
    }

    private static function displayName(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 120) {
            throw new InvalidArgumentException('Название модели в CRM обязательно.');
        }

        return trim($value);
    }

    /** @param array<string, mixed> $data */
    private static function requiredRate(array $data, string $key, ?int $default): int
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            if ($default === null) {
                throw new InvalidArgumentException('Укажите стоимость входных данных и ответа модели.');
            }

            return $default;
        }

        return self::rate($data[$key], $key);
    }

    /** @param array<string, mixed> $data */
    private static function optionalRate(array $data, string $key, ?int $default): ?int
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return $default;
        }

        return self::rate($data[$key], $key);
    }

    private static function rate(mixed $value, string $key): int
    {
        return is_int($value)
            ? AiMoney::rateUnitsFromMinorUnits(AiMoney::canonicalMinorUnits($value, $key))
            : AiMoney::rateUnitsFromDecimal($value, $key);
    }

    private static function compatibilityMinor(int $rate): int
    {
        return AiMoney::minorUnitsFromRateUnitsCeiling($rate);
    }

    private static function compatibilityNullableMinor(?int $rate): ?int
    {
        return $rate === null ? null : self::compatibilityMinor($rate);
    }

    /** @return list<string> */
    private static function capabilityList(mixed $value): array
    {
        $modalityValues = array_map(
            static fn (AiModelModality $modality): string => $modality->value,
            AiModelModality::cases(),
        );
        $capabilities = [];

        foreach (self::stringList($value, 'capabilities') as $capability) {
            if (in_array($capability, $modalityValues, true)) {
                continue;
            }

            if (AiCapability::tryFrom($capability) === null) {
                throw new InvalidArgumentException('Выбрана неизвестная задача Chuklov.');
            }

            $capabilities[] = $capability;
        }

        return array_values(array_unique($capabilities));
    }

    /** @return list<string> */
    private static function modalityList(mixed $value): array
    {
        $modalities = [];
        foreach (self::stringList($value, 'model_modalities') as $modality) {
            if (AiModelModality::tryFrom($modality) === null) {
                throw new InvalidArgumentException('Выбран неизвестный тип входных данных модели.');
            }

            $modalities[] = $modality;
        }

        return array_values(array_unique($modalities));
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $field): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("{$field} must be a list of strings.");
        }

        $items = [];
        foreach ($value as $item) {
            if ($item instanceof BackedEnum) {
                $item = $item->value;
            }

            if (! is_string($item)) {
                throw new InvalidArgumentException("{$field} must be a list of strings.");
            }

            $item = trim($item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return array_values(array_unique($items));
    }

    /** @return list<string> */
    private static function unsupportedMeters(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $values = is_array($value)
            ? self::stringList($value, 'unsupported_meters')
            : (is_string($value) ? preg_split('/\\s*,\\s*/', trim($value)) ?: [] : null);
        if ($values === null) {
            throw new InvalidArgumentException('unsupported_meters must be a string or list of strings.');
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (string $meter): string => trim($meter), $values),
            static fn (string $meter): bool => $meter !== '',
        )));
    }

    private static function boolean(mixed $value, string $field): bool
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException("{$field} must be a boolean.");
        }

        return $value;
    }

    private static function unknownPricing(): AiPricingSnapshot
    {
        return new AiPricingSnapshot(
            cacheReadInputCostPerMillionMinorUnits: null,
            cacheWriteInputCostPerMillionMinorUnits: null,
            reasoningCostPerMillionMinorUnits: null,
            fixedRequestCostMinorUnits: null,
            pricingSource: AiPricingSnapshot::SOURCE_UNKNOWN,
        );
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
