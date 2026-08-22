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

    /** @return array<string, string> */
    public static function optionsForProvider(
        mixed $provider,
        ?string $currentModel = null,
        ?DateTimeInterface $asOf = null,
    ): array {
        $provider = AiProviderCatalog::normalize($provider);
        $currentModel = $currentModel === null ? null : trim($currentModel);
        $options = [];

        foreach (self::all($asOf) as $definition) {
            if ($definition->provider !== $provider
                || (! $definition->lifecycleStatus->isSelectableForNewConfiguration()
                    && $definition->modelName !== $currentModel)) {
                continue;
            }

            $label = $definition->displayName.' · '.$definition->family;
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

        return 'Стоимость по каталогу: $'.AiMoney::decimalFromMinorUnits($pricing->inputCostPerMillionMinorUnits)
            .' вход / $'.AiMoney::decimalFromMinorUnits($pricing->outputCostPerMillionMinorUnits)
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
            return true;
        }

        if ($pricing->hasCatalogPricingMetadata()
            && ($pricing->catalogPricingEffectiveFrom !== $definition->pricing->catalogPricingEffectiveFrom
                || $pricing->catalogPricingEffectiveUntil !== $definition->pricing->catalogPricingEffectiveUntil
                || $pricing->catalogPricingAsOf !== $definition->pricing->catalogPricingAsOf)) {
            return true;
        }

        return ! $pricing->sameBillablePricing($definition->pricing);
    }

    /** @return list<string> */
    public static function humanSupportedInputs(AiModelDefinition $definition): array
    {
        $inputs = [];

        foreach ($definition->supportedCapabilities as $capability) {
            $label = match ($capability) {
                'text_generation' => 'текст',
                'structured_output' => 'структурированные ответы',
                default => null,
            };

            if ($label !== null) {
                $inputs[] = $label;
            }
        }

        foreach ($definition->modalities as $modality) {
            $inputs[] = match ($modality) {
                AiModelModality::ImageInput => 'изображения',
                AiModelModality::DocumentInput => 'документы',
            };
        }

        return array_values(array_unique($inputs));
    }
}
