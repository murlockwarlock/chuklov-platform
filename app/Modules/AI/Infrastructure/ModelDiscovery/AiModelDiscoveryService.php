<?php

namespace App\Modules\AI\Infrastructure\ModelDiscovery;

use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Registry\AiModelCatalog;
use App\Modules\AI\Domain\Registry\AiModelDefinition;
use App\Modules\AI\Domain\Registry\AiProviderCatalog;
use App\Modules\AI\Domain\ValueObjects\AiMoney;
use App\Modules\AI\Infrastructure\Providers\AiProviderExecutionConfiguration;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class AiModelDiscoveryService
{
    private const int FRESH_TTL_SECONDS = 600;

    private const int STALE_TTL_SECONDS = 86_400;

    private const int MAX_MODELS = 250;

    public function definitionFor(AiProviderConfiguration $provider, mixed $model): ?AiModelDefinition
    {
        if (! is_string($model) || trim($model) === '') {
            return null;
        }

        $static = AiModelCatalog::find($provider->provider_name, $model);
        if ($static instanceof AiModelDefinition) {
            return $static;
        }

        $model = trim($model);
        foreach ($this->discover($provider)->models() as $definition) {
            if ($definition->provider === AiProviderCatalog::normalize($provider->provider_name)
                && $definition->modelName === $model) {
                return $definition;
            }
        }

        return null;
    }

    /** @return list<AiModelDefinition> */
    public function definitionsFor(AiProviderConfiguration $provider): array
    {
        return $this->discover($provider)->models();
    }

    public function discover(AiProviderConfiguration $provider): AiModelDiscoveryResult
    {
        $strategy = AiProviderCatalog::discoveryStrategy($provider->provider_name);
        if (! in_array($strategy, ['official_api', 'local_api'], true)) {
            return new AiModelDiscoveryResult([], error: 'Для этого провайдера используется встроенный или расширенный режим выбора модели.');
        }

        $driver = AiProviderCatalog::normalize($provider->provider_name);
        $credential = $provider->credential;
        $credentialValid = $credential !== null
            && (int) $credential->organization_id === (int) $provider->organization_id
            && $credential->provider === $provider->provider_name
            && $credential->status === CredentialStatus::Active;
        if (! $credentialValid && ! in_array($driver, ['ollama', 'openai_compatible'], true)) {
            return new AiModelDiscoveryResult([], error: 'Подключите действующий ключ провайдера для списка моделей.');
        }

        try {
            $options = AiProviderExecutionConfiguration::normalizeOptions(
                $driver,
                (array) ($provider->options ?? []),
            );
        } catch (\Throwable) {
            return new AiModelDiscoveryResult([], error: 'Параметры endpoint провайдера требуют исправления перед обнаружением моделей.');
        }

        $freshKey = $this->cacheKey($provider, $credential, $options, 'fresh');
        $staleKey = $this->cacheKey($provider, $credential, $options, 'stale');
        $fresh = Cache::get($freshKey);
        if (is_array($fresh)) {
            return new AiModelDiscoveryResult(
                definitions: $this->boundedDefinitions($fresh),
            );
        }

        $stale = Cache::get($staleKey);
        try {
            $definitions = $this->fetch(
                provider: $provider,
                secret: $credentialValid
                    ? (string) ($credential?->credentials['api_key'] ?? $credential?->credentials['key'] ?? '')
                    : '',
                options: $options,
            );
            Cache::put($freshKey, $definitions, now()->addSeconds(self::FRESH_TTL_SECONDS));
            Cache::put($staleKey, $definitions, now()->addSeconds(self::STALE_TTL_SECONDS));

            return new AiModelDiscoveryResult($definitions);
        } catch (\Throwable) {
            return new AiModelDiscoveryResult(
                definitions: is_array($stale) ? $this->boundedDefinitions($stale) : [],
                stale: is_array($stale),
                error: 'Не удалось обновить список моделей провайдера. Сохранённая настройка остаётся доступной.',
            );
        }
    }

    /**
     * @param  array<string, string>  $options
     * @return list<array<string, mixed>>
     */
    private function fetch(AiProviderConfiguration $provider, string $secret, array $options): array
    {
        $driver = AiProviderCatalog::normalize($provider->provider_name);
        $request = Http::withoutRedirecting()->acceptJson()->connectTimeout(3)->timeout(5);
        if ($secret !== '') {
            $request = $request->withToken($secret);
        }

        $response = match ($driver) {
            'openrouter' => $request->get('https://openrouter.ai/api/v1/models'),
            'openai_compatible' => $request->get($this->compatibleModelsEndpoint($options)),
            'ollama' => $request->get($this->ollamaModelsEndpoint($options)),
            default => throw new RuntimeException('Model discovery is unavailable for this provider.'),
        };

        if (! $response->successful()) {
            throw new RuntimeException('Model discovery returned a non-success response.');
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Model discovery returned an invalid response.');
        }

        return match ($driver) {
            'openrouter' => $this->openRouterDefinitions($payload),
            'openai_compatible' => $this->openAiCompatibleDefinitions($payload),
            'ollama' => $this->ollamaDefinitions($payload),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function openRouterDefinitions(array $payload): array
    {
        $items = $payload['data'] ?? null;
        if (! is_array($items)) {
            throw new RuntimeException('OpenRouter model response is invalid.');
        }

        $definitions = [];
        foreach (array_slice($items, 0, self::MAX_MODELS) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $modelId = $this->boundedString($item['id'] ?? null, 120);
            if ($modelId === null || $this->isInactive($item)) {
                continue;
            }

            $architecture = is_array($item['architecture'] ?? null) ? $item['architecture'] : [];
            $inputModalities = is_array($architecture['input_modalities'] ?? null)
                ? $architecture['input_modalities']
                : [];
            $outputModalities = is_array($architecture['output_modalities'] ?? null)
                ? $architecture['output_modalities']
                : [];
            if (! in_array('text', $outputModalities, true)) {
                continue;
            }

            $pricing = $this->openRouterPricing($item['pricing'] ?? null);
            $supportedCapabilities = ['text_generation'];
            $supportedParameters = is_array($item['supported_parameters'] ?? null)
                ? $item['supported_parameters']
                : [];
            if (in_array('response_format', $supportedParameters, true)
                || in_array('structured_outputs', $supportedParameters, true)) {
                $supportedCapabilities[] = 'structured_output';
            }

            $definitions[] = [
                'provider' => 'openrouter',
                'model' => $modelId,
                'display_name' => $this->boundedString($item['name'] ?? null, 120) ?? $modelId,
                'family' => $this->boundedString($item['canonical_slug'] ?? null, 120) ?? $modelId,
                'summary' => $this->boundedString($item['description'] ?? null, 240) ?? 'Модель доступна в подключённом каталоге OpenRouter.',
                'positioning' => 'Доступна в подключённом аккаунте',
                'recommended' => false,
                'context_window_tokens' => $this->positiveInt($item['context_length'] ?? null),
                'supported_capabilities' => $supportedCapabilities,
                'modalities' => $this->modalities($inputModalities),
                'pricing' => $pricing,
                'lifecycle' => 'active',
                'catalog_source' => 'https://openrouter.ai/docs/api/api-reference/models/get-models',
                'pricing_as_of' => now()->toDateString(),
            ];
        }

        return $this->boundedDefinitions($definitions);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function openAiCompatibleDefinitions(array $payload): array
    {
        $items = $payload['data'] ?? null;
        if (! is_array($items)) {
            throw new RuntimeException('OpenAI-compatible model response is invalid.');
        }

        $definitions = [];
        foreach (array_slice($items, 0, self::MAX_MODELS) as $item) {
            if (is_string($item)) {
                $item = ['id' => $item];
            }
            if (! is_array($item)) {
                continue;
            }

            $modelId = $this->boundedString($item['id'] ?? $item['name'] ?? null, 120);
            if ($modelId === null) {
                continue;
            }

            $modalities = is_array($item['input_modalities'] ?? null)
                ? $this->modalities($item['input_modalities'])
                : [];
            $definitions[] = [
                'provider' => 'openai_compatible',
                'model' => $modelId,
                'display_name' => $this->boundedString($item['name'] ?? null, 120) ?? $modelId,
                'family' => $this->boundedString($item['owned_by'] ?? null, 120) ?? 'Подключённый каталог',
                'summary' => 'Модель доступна на подключённом OpenAI-compatible endpoint.',
                'positioning' => 'Доступна в подключённом аккаунте',
                'recommended' => false,
                'context_window_tokens' => $this->positiveInt($item['context_length'] ?? null),
                'supported_capabilities' => ['text_generation'],
                'modalities' => $modalities,
                'lifecycle' => 'active',
                'catalog_source' => 'https://platform.openai.com/docs/api-reference/models/list',
                'pricing_as_of' => now()->toDateString(),
            ];
        }

        return $this->boundedDefinitions($definitions);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function ollamaDefinitions(array $payload): array
    {
        $items = $payload['models'] ?? null;
        if (! is_array($items)) {
            throw new RuntimeException('Ollama model response is invalid.');
        }

        $definitions = [];
        foreach (array_slice($items, 0, self::MAX_MODELS) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $modelId = $this->boundedString($item['name'] ?? $item['model'] ?? null, 120);
            if ($modelId === null) {
                continue;
            }

            $details = is_array($item['details'] ?? null) ? $item['details'] : [];
            $definitions[] = [
                'provider' => 'ollama',
                'model' => $modelId,
                'display_name' => $modelId,
                'family' => $this->boundedString($details['family'] ?? null, 120) ?? 'Локальная модель',
                'summary' => 'Модель установлена на подключённом Ollama сервере.',
                'positioning' => 'Доступна локально',
                'recommended' => false,
                'supported_capabilities' => ['text_generation'],
                'modalities' => [],
                'lifecycle' => 'active',
                'catalog_source' => 'https://docs.ollama.com/api/tags',
                'pricing_as_of' => now()->toDateString(),
            ];
        }

        return $this->boundedDefinitions($definitions);
    }

    /**
     * @param  array<string, mixed>|null  $pricing
     * @return array<string, mixed>|null
     */
    private function openRouterPricing(mixed $pricing): ?array
    {
        if (! is_array($pricing)) {
            return null;
        }

        $prompt = $this->perTokenRate($pricing['prompt'] ?? null);
        $completion = $this->perTokenRate($pricing['completion'] ?? null);
        if ($prompt === null || $completion === null) {
            return null;
        }

        $unsupportedMeters = [];
        foreach (['image', 'web_search', 'internal_reasoning'] as $meter) {
            if ($this->isNonZero($pricing[$meter] ?? null)) {
                $unsupportedMeters[] = $meter;
            }
        }

        $requestRate = $this->perRequestRate($pricing['request'] ?? null);

        return [
            'currency' => 'USD',
            'input_rate_per_million_units' => $prompt,
            'output_rate_per_million_units' => $completion,
            'cache_read_input_rate_per_million_units' => $this->perTokenRate($pricing['input_cache_read'] ?? null),
            'cache_write_input_rate_per_million_units' => $this->perTokenRate($pricing['input_cache_write'] ?? null),
            'fixed_request_cost_applicable' => $requestRate !== null,
            'fixed_request_rate_units' => $requestRate,
            'unsupported_meters' => $unsupportedMeters,
        ];
    }

    private function perTokenRate(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return AiMoney::rateUnitsFromPerTokenDecimal($value, 'OpenRouter token price');
        } catch (\Throwable) {
            return null;
        }
    }

    private function perRequestRate(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! $this->isNonZero($value)) {
            return 0;
        }

        try {
            return AiMoney::rateUnitsFromDecimal($value, 'OpenRouter request price');
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $item */
    private function isInactive(array $item): bool
    {
        $expiration = $item['expiration_date'] ?? null;
        if (is_string($expiration) && trim($expiration) !== '') {
            try {
                if (now()->greaterThanOrEqualTo($expiration)) {
                    return true;
                }
            } catch (\Throwable) {
                return true;
            }
        }

        $text = strtolower(implode(' ', array_filter([
            is_string($item['id'] ?? null) ? $item['id'] : null,
            is_string($item['name'] ?? null) ? $item['name'] : null,
        ])));

        return str_contains($text, 'deprecated')
            || str_contains($text, 'retired')
            || str_contains($text, 'preview')
            || str_contains($text, 'experimental');
    }

    /**
     * @param  array<mixed>  $modalities
     * @return list<string>
     */
    private function modalities(array $modalities): array
    {
        $result = [];
        foreach ($modalities as $modality) {
            if (! is_string($modality)) {
                continue;
            }

            $modality = strtolower(trim($modality));
            if (in_array($modality, ['image', 'image_url', 'vision'], true)) {
                $result[AiModelModality::ImageInput->value] = AiModelModality::ImageInput->value;
            }
            if (in_array($modality, ['file', 'document', 'pdf'], true)) {
                $result[AiModelModality::DocumentInput->value] = AiModelModality::DocumentInput->value;
            }
        }

        return array_values($result);
    }

    /** @param array<string, string> $options */
    private function compatibleModelsEndpoint(array $options): string
    {
        $baseUrl = (string) ($options['base_url'] ?? '');

        return rtrim($baseUrl, '/').'/models';
    }

    /** @param array<string, string> $options */
    private function ollamaModelsEndpoint(array $options): string
    {
        $baseUrl = (string) ($options['base_url'] ?? 'http://localhost:11434');

        return rtrim($baseUrl, '/').'/api/tags';
    }

    /** @param array<string, string> $options */
    private function cacheKey(
        AiProviderConfiguration $provider,
        ?OrganizationCredential $credential,
        array $options,
        string $age,
    ): string {
        ksort($options);
        $optionsDigest = hash('sha256', json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return 'ai:model-discovery:'.(int) $provider->organization_id.':'.(int) $provider->getKey().':'
            .(int) ($credential?->getKey() ?? 0).':'.((string) ($credential->revision_id ?? 'none')).':'
            .$optionsDigest.':'.$age;
    }

    private function boundedString(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' || mb_strlen($value) > $limit ? null : $value;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function isNonZero(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        try {
            return AiMoney::rateUnitsFromDecimal($value) > 0;
        } catch (\Throwable) {
            return true;
        }
    }

    /** @return list<array<string, mixed>> */
    private function boundedDefinitions(mixed $definitions): array
    {
        if (! is_array($definitions)) {
            return [];
        }

        return array_values(array_filter(
            array_slice($definitions, 0, self::MAX_MODELS),
            static fn (mixed $definition): bool => is_array($definition),
        ));
    }
}
