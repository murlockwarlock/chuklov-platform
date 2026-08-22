<?php

namespace App\Modules\AI\Infrastructure\Providers;

use App\Modules\AI\Domain\Exceptions\AiProviderProbeUnsupportedException;
use App\Modules\AI\Domain\Registry\AiProviderCatalog;

final class AiProviderExecutionConfiguration
{
    /** @var array<string, string> */
    private const CANONICAL_PROBE_ENDPOINTS = [
        'openai' => 'https://api.openai.com/v1/models',
        'groq' => 'https://api.groq.com/openai/v1/models',
        'deepseek' => 'https://api.deepseek.com/models',
        'mistral' => 'https://api.mistral.ai/v1/models',
        'xai' => 'https://api.x.ai/v1/models',
        'openrouter' => 'https://openrouter.ai/api/v1/models',
        'anthropic' => 'https://api.anthropic.com/v1/models',
        'gemini' => 'https://generativelanguage.googleapis.com/v1beta/models',
    ];

    /** @param array<string, mixed> $options */
    public static function digest(string $providerName, array $options = []): string
    {
        $provider = self::provider($providerName);
        $normalizedOptions = self::normalizeOptions($provider, $options);
        $endpoint = self::executionEndpoint($provider, $normalizedOptions);

        return hash('sha256', json_encode([
            'provider' => $provider,
            'execution_endpoint' => $endpoint,
            'options' => $normalizedOptions,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $options */
    public static function assertSupportedOptions(array $options, ?string $providerName = null): void
    {
        if ($providerName === null) {
            if ($options !== []) {
                throw new AiProviderProbeUnsupportedException(
                    'Custom provider endpoints and execution options are not supported for M10 provider probes.',
                );
            }

            return;
        }

        self::normalizeOptions($providerName, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function normalizeOptions(string $providerName, array $options): array
    {
        $provider = self::provider($providerName);
        $options = array_filter(
            $options,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
        foreach (['embedding_model', 'reranking_model'] as $legacyKnowledgeOption) {
            unset($options[$legacyKnowledgeOption]);
        }

        $allowed = match ($provider) {
            'openai_compatible', 'ollama' => ['base_url', 'url'],
            'azure' => ['base_url', 'url', 'api_version', 'deployment'],
            'bedrock' => ['region'],
            default => [],
        };
        $unsupported = array_diff(array_keys($options), $allowed);
        if ($unsupported !== []) {
            if (! in_array($provider, ['openai_compatible', 'ollama', 'azure', 'bedrock'], true)) {
                throw new AiProviderProbeUnsupportedException(
                    'Custom provider endpoints and execution options are not supported for M10 provider probes.',
                );
            }

            throw new AiProviderProbeUnsupportedException(
                'The selected provider does not support the supplied execution options.',
            );
        }

        $normalized = [];
        $baseUrl = self::optionString($options['base_url'] ?? $options['url'] ?? null, 'base_url');
        if (($options['base_url'] ?? null) !== null
            && ($options['url'] ?? null) !== null
            && (string) $options['base_url'] !== (string) $options['url']) {
            throw new AiProviderProbeUnsupportedException('Use one provider endpoint field only.');
        }

        if ($baseUrl !== null) {
            $normalized['base_url'] = AiProviderEndpointGuard::assertSafeUrl($baseUrl, 'base_url', $provider);
        } elseif (in_array($provider, ['openai_compatible', 'azure'], true)) {
            throw new AiProviderProbeUnsupportedException(
                'A provider endpoint is required before the connection can be checked.',
            );
        }

        if ($provider === 'azure') {
            if (array_key_exists('api_version', $options)) {
                $apiVersion = self::optionString($options['api_version'], 'api_version');
                if ($apiVersion === null || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}(?:-[a-z0-9-]+)?$/i', $apiVersion) !== 1) {
                    throw new AiProviderProbeUnsupportedException('The Azure API version is invalid.');
                }

                $normalized['api_version'] = $apiVersion;
            }

            if (array_key_exists('deployment', $options)) {
                $deployment = self::optionString($options['deployment'], 'deployment');
                if ($deployment === null) {
                    throw new AiProviderProbeUnsupportedException('The Azure deployment name is invalid.');
                }

                $normalized['deployment'] = $deployment;
            }
        }

        if ($provider === 'bedrock' && array_key_exists('region', $options)) {
            $region = self::optionString($options['region'], 'region');
            if ($region === null || preg_match('/^[a-z]{2}(?:-gov)?-[a-z0-9-]+-[0-9]$/', $region) !== 1) {
                throw new AiProviderProbeUnsupportedException('The Bedrock region is invalid.');
            }

            $normalized['region'] = $region;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function sdkOptions(string $providerName, array $options): array
    {
        $provider = self::provider($providerName);
        $normalized = self::normalizeOptions($provider, $options);

        return match ($provider) {
            'openai_compatible', 'ollama' => $normalized === [] ? [] : ['url' => $normalized['base_url']],
            'azure' => array_filter([
                'url' => $normalized['base_url'] ?? null,
                'api_version' => $normalized['api_version'] ?? null,
                'deployment' => $normalized['deployment'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            'bedrock' => $normalized,
            default => [],
        };
    }

    public static function canonicalProbeEndpoint(string $providerName): ?string
    {
        try {
            $providerName = AiProviderCatalog::normalize($providerName);
        } catch (\Throwable) {
            return null;
        }

        return self::CANONICAL_PROBE_ENDPOINTS[$providerName] ?? null;
    }

    /** @param array<string, mixed> $options */
    public static function probeEndpoint(string $providerName, array $options = []): string
    {
        $provider = self::provider($providerName);
        $normalized = self::normalizeOptions($provider, $options);

        return self::executionEndpoint($provider, $normalized);
    }

    public static function providerRequiresSecret(string $providerName): bool
    {
        return ! in_array(self::provider($providerName), ['ollama', 'openai_compatible'], true);
    }

    private static function provider(string $providerName): string
    {
        try {
            return AiProviderCatalog::normalize($providerName);
        } catch (\Throwable $exception) {
            throw new AiProviderProbeUnsupportedException(
                'The selected provider does not have a supported execution configuration.',
                previous: $exception,
            );
        }
    }

    /** @param array<string, string> $options */
    private static function executionEndpoint(string $provider, array $options): string
    {
        if (isset(self::CANONICAL_PROBE_ENDPOINTS[$provider])) {
            return self::CANONICAL_PROBE_ENDPOINTS[$provider];
        }

        if ($provider === 'ollama') {
            return rtrim($options['base_url'] ?? 'http://localhost:11434', '/').'/api/tags';
        }

        if ($provider === 'openai_compatible') {
            return rtrim($options['base_url'], '/').'/models';
        }

        if ($provider === 'azure') {
            return rtrim($options['base_url'], '/').'/openai/v1/models';
        }

        throw new AiProviderProbeUnsupportedException(
            'This provider does not have a supported canonical execution and probe endpoint.',
        );
    }

    private static function optionString(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 500) {
            throw new AiProviderProbeUnsupportedException("The provider option {$field} is invalid.");
        }

        return trim($value);
    }
}
