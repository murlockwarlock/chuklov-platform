<?php

namespace App\Modules\AI\Domain\Registry;

use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\ValueObjects\AiMoney;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use DateTimeInterface;
use InvalidArgumentException;

final class AiModelCatalog
{
    public const string CUSTOM_MODEL = '__custom__';

    /** @return list<AiModelDefinition> */
    public static function all(?DateTimeInterface $asOf = null): array
    {
        return array_map(
            static fn (array $definition): AiModelDefinition => AiModelDefinition::fromArray($definition, $asOf),
            array_values((array) config('ai.model_catalog', [])),
        );
    }

    /**
     * @param  list<mixed>  $additionalDefinitions
     * @return array<string, string>
     */
    public static function optionsForProvider(
        mixed $provider,
        ?string $currentModel = null,
        ?DateTimeInterface $asOf = null,
        array $additionalDefinitions = [],
    ): array {
        $provider = AiProviderCatalog::normalize($provider);
        $currentModel = $currentModel === null ? null : trim($currentModel);
        $options = [];
        $definitions = self::all($asOf);
        foreach ($additionalDefinitions as $additionalDefinition) {
            if ($additionalDefinition instanceof AiModelDefinition) {
                $definitions[] = $additionalDefinition;
            }
        }

        foreach ($definitions as $definition) {
            if ($definition->provider !== $provider
                || (! $definition->lifecycleStatus->isSelectableForNewConfiguration()
                    && $definition->modelName !== $currentModel)) {
                continue;
            }

            $label = $definition->displayName.' · '.($definition->positioning ?? $definition->family);
            if ($definition->recommended) {
                $label .= ' · Рекомендуется';
            }
            $inputs = self::humanSupportedInputs($definition);
            if ($inputs !== []) {
                $label .= ' · '.implode(', ', $inputs);
            }
            if ($definition->pricing !== null) {
                $label .= ' · $'.AiMoney::displayDecimalFromRateUnits($definition->pricing->inputRatePerMillionUnits())
                    .' / $'.AiMoney::displayDecimalFromRateUnits($definition->pricing->outputRatePerMillionUnits()).' за 1 млн токенов';
            }
            if (! $definition->lifecycleStatus->isSelectableForNewConfiguration()) {
                $label .= ' · '.$definition->lifecycleStatus->label();
            }

            $options[$definition->modelName] = $label;
        }

        $options[self::CUSTOM_MODEL] = 'Другая модель / Указать вручную';

        return $options;
    }

    public static function find(
        mixed $provider,
        mixed $modelName,
        ?DateTimeInterface $asOf = null,
    ): ?AiModelDefinition {
        $provider = AiProviderCatalog::normalize($provider);

        if (! is_string($modelName) || trim($modelName) === '') {
            return null;
        }

        foreach (self::all($asOf) as $definition) {
            if ($definition->provider === $provider && $definition->modelName === trim($modelName)) {
                return $definition;
            }
        }

        return null;
    }

    public static function selection(
        mixed $provider,
        mixed $modelName,
        ?DateTimeInterface $asOf = null,
    ): string {
        $definition = self::find($provider, $modelName, $asOf);

        return $definition instanceof AiModelDefinition ? $definition->modelName : self::CUSTOM_MODEL;
    }

    public static function selectedDefinition(
        mixed $provider,
        mixed $selection,
        ?DateTimeInterface $asOf = null,
    ): ?AiModelDefinition {
        if ($selection === null || $selection === '' || $selection === self::CUSTOM_MODEL) {
            return null;
        }

        $definition = self::find($provider, $selection, $asOf);
        if ($definition === null) {
            throw new InvalidArgumentException('Выбранная модель отсутствует в каталоге. Укажите её через расширенный путь.');
        }

        return $definition;
    }

    public static function pricingText(?AiPricingSnapshot $pricing): string
    {
        if ($pricing === null || $pricing->pricingSource !== AiPricingSnapshot::SOURCE_CATALOG) {
            return 'Стоимость не задана';
        }

        return 'Стоимость по каталогу: $'.AiMoney::displayDecimalFromRateUnits($pricing->inputRatePerMillionUnits())
            .' вход / $'.AiMoney::displayDecimalFromRateUnits($pricing->outputRatePerMillionUnits())
            .' ответ';
    }

    public static function pricingIsStale(
        mixed $provider,
        mixed $modelName,
        AiPricingSnapshot $pricing,
        ?DateTimeInterface $asOf = null,
    ): bool {
        if ($pricing->pricingSource !== AiPricingSnapshot::SOURCE_CATALOG) {
            return false;
        }

        $definition = self::find($provider, $modelName, $asOf);
        if ($definition === null || $definition->pricing === null) {
            return $definition === null
                ? ! self::isImmutableDiscoveredPricing($provider, $pricing)
                : true;
        }

        if ($definition->pricing->hasCatalogPricingMetadata()
            || $pricing->hasCatalogPricingMetadata()) {
            if ($pricing->catalogPricingEffectiveFrom !== $definition->pricing->catalogPricingEffectiveFrom
                || $pricing->catalogPricingEffectiveUntil !== $definition->pricing->catalogPricingEffectiveUntil
                || $pricing->catalogPricingAsOf !== $definition->pricing->catalogPricingAsOf
                || $pricing->catalogSource !== $definition->pricing->catalogSource) {
                return true;
            }
        }

        return ! $pricing->sameBillablePricing($definition->pricing);
    }

    public static function isImmutableDiscoveredPricing(mixed $provider, AiPricingSnapshot $pricing): bool
    {
        if (! $pricing->hasCatalogPricingMetadata() || $pricing->catalogSource === null) {
            return false;
        }

        return self::isDiscoveredCatalogSource($provider, $pricing->catalogSource);
    }

    public static function isDiscoveredDefinition(?AiModelDefinition $definition): bool
    {
        return $definition !== null
            && self::isDiscoveredCatalogSource($definition->provider, $definition->catalogSource);
    }

    private static function isDiscoveredCatalogSource(mixed $provider, ?string $catalogSource): bool
    {
        if ($catalogSource === null) {
            return false;
        }

        $provider = AiProviderCatalog::normalize($provider);
        $allowedSources = match ($provider) {
            'openrouter' => ['https://openrouter.ai/docs/api/api-reference/models/get-models'],
            'openai_compatible' => ['https://platform.openai.com/docs/api-reference/models/list'],
            'ollama' => ['https://docs.ollama.com/api/tags'],
            default => [],
        };

        return in_array($catalogSource, $allowedSources, true);
    }

    /** @return list<string> */
    public static function humanSupportedInputs(AiModelDefinition $definition): array
    {
        $inputs = [];

        foreach ($definition->supportedCapabilities as $capability) {
            $label = match ($capability) {
                'text_generation' => 'Текст',
                'structured_output' => 'Структурированные ответы',
                default => null,
            };

            if ($label !== null) {
                $inputs[] = $label;
            }
        }

        foreach ($definition->modalities as $modality) {
            $inputs[] = match ($modality) {
                AiModelModality::ImageInput => 'Изображения и сканы',
                AiModelModality::DocumentInput => 'Документы / PDF',
            };
        }

        return array_values(array_unique($inputs));
    }
}
