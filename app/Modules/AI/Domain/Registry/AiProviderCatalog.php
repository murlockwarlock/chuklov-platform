<?php

namespace App\Modules\AI\Domain\Registry;

use InvalidArgumentException;

final class AiProviderCatalog
{
    /** @var array<string, array{label: string, modalities: list<string>}> */
    private const PROVIDERS = [
        'openai' => ['label' => 'OpenAI', 'modalities' => ['image_input', 'document_input']],
        'azure' => ['label' => 'Azure OpenAI', 'modalities' => ['image_input', 'document_input']],
        'anthropic' => ['label' => 'Anthropic', 'modalities' => ['image_input', 'document_input']],
        'gemini' => ['label' => 'Google Gemini', 'modalities' => ['image_input', 'document_input']],
        'openrouter' => ['label' => 'OpenRouter', 'modalities' => ['image_input', 'document_input']],
        'xai' => ['label' => 'xAI', 'modalities' => ['image_input', 'document_input']],
        'bedrock' => ['label' => 'Amazon Bedrock', 'modalities' => ['image_input', 'document_input']],
        'openai_compatible' => ['label' => 'OpenAI-compatible', 'modalities' => ['image_input']],
        'groq' => ['label' => 'Groq', 'modalities' => ['image_input']],
        'deepseek' => ['label' => 'DeepSeek', 'modalities' => ['image_input']],
        'ollama' => ['label' => 'Ollama', 'modalities' => ['image_input']],
        'mistral' => ['label' => 'Mistral', 'modalities' => ['image_input']],
    ];

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_map(
            static fn (array $provider): string => $provider['label'],
            self::PROVIDERS,
        );
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public static function normalize(mixed $providerName): string
    {
        if (! is_string($providerName)) {
            throw new InvalidArgumentException('The AI provider is invalid.');
        }

        $providerName = strtolower(trim($providerName));

        if (! array_key_exists($providerName, self::PROVIDERS)) {
            throw new InvalidArgumentException('The selected AI provider is not supported.');
        }

        return $providerName;
    }

    public static function label(mixed $providerName): string
    {
        return self::PROVIDERS[self::normalize($providerName)]['label'];
    }

    /** @return list<string> */
    public static function modalities(mixed $providerName): array
    {
        return self::PROVIDERS[self::normalize($providerName)]['modalities'];
    }
}
