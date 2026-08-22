<?php

namespace App\Modules\Knowledge\Domain\Registry;

use App\Modules\AI\Domain\Registry\AiProviderCatalog;
use InvalidArgumentException;

final class KnowledgeModelCatalog
{
    public const string Embedding = 'embedding';

    public const string Reranking = 'reranking';

    /** @return array<string, string> */
    public static function optionsFor(string $role, ?string $provider = null): array
    {
        return collect(self::definitionsFor($role, $provider))
            ->mapWithKeys(static fn (array $definition): array => [
                (string) $definition['model'] => self::label($definition),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public static function definitionsFor(string $role, ?string $provider = null): array
    {
        self::assertRole($role);
        $normalizedProvider = $provider === null ? null : AiProviderCatalog::normalize($provider);
        $definitions = (array) config('rag.model_catalog.'.$role, []);

        return array_values(array_filter(
            $definitions,
            static fn (mixed $definition): bool => is_array($definition)
                && ($normalizedProvider === null || ($definition['provider'] ?? null) === $normalizedProvider)
                && ($definition['lifecycle'] ?? 'active') === 'active',
        ));
    }

    /** @return array<string, mixed>|null */
    public static function find(string $role, string $provider, string $model): ?array
    {
        $model = trim($model);
        foreach (self::definitionsFor($role, $provider) as $definition) {
            if (($definition['model'] ?? null) === $model) {
                return $definition;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    public static function providersFor(string $role): array
    {
        return collect(self::definitionsFor($role))
            ->mapWithKeys(static fn (array $definition): array => [
                (string) $definition['provider'] => AiProviderCatalog::label($definition['provider']),
            ])
            ->all();
    }

    /** @param array<string, mixed> $definition */
    public static function label(array $definition): string
    {
        $label = (string) ($definition['display_name'] ?? $definition['model'] ?? 'Модель');
        $positioning = trim((string) ($definition['positioning'] ?? ''));
        $dimensions = isset($definition['dimensions']) ? ' · '.(int) $definition['dimensions'].' измерений' : '';
        $pricing = self::pricingText($definition);

        return implode('', [$label, $positioning !== '' ? ' · '.$positioning : '', $dimensions, $pricing !== '' ? ' · '.$pricing : '']);
    }

    /** @param array<string, mixed> $definition */
    public static function pricingText(array $definition): string
    {
        if (isset($definition['input_price_per_million'])) {
            return 'ввод $'.(string) $definition['input_price_per_million'].'/1M токенов';
        }

        if (($definition['billing_meter'] ?? null) === 'search_units') {
            return 'оплата за поисковые единицы';
        }

        return 'стоимость зависит от тарифа провайдера';
    }

    private static function assertRole(string $role): void
    {
        if (! in_array($role, [self::Embedding, self::Reranking], true)) {
            throw new InvalidArgumentException('The knowledge model role is invalid.');
        }
    }
}
