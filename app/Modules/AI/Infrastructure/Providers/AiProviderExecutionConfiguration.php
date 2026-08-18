<?php

namespace App\Modules\AI\Infrastructure\Providers;

use App\Modules\AI\Domain\Exceptions\AiProviderProbeUnsupportedException;

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
        self::assertSupportedOptions($options);

        $provider = strtolower(trim($providerName));
        $endpoint = self::CANONICAL_PROBE_ENDPOINTS[$provider]
            ?? throw new AiProviderProbeUnsupportedException(
                'This provider does not have a supported canonical execution and probe endpoint.',
            );

        return hash('sha256', json_encode([
            'provider' => $provider,
            'canonical_probe_endpoint' => $endpoint,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $options */
    public static function assertSupportedOptions(array $options): void
    {
        if ($options !== []) {
            throw new AiProviderProbeUnsupportedException(
                'Custom provider endpoints and execution options are not supported for M10 provider probes.',
            );
        }
    }

    public static function canonicalProbeEndpoint(string $providerName): ?string
    {
        return self::CANONICAL_PROBE_ENDPOINTS[strtolower(trim($providerName))] ?? null;
    }
}
