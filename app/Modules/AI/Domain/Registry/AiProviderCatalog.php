<?php

namespace App\Modules\AI\Domain\Registry;

use InvalidArgumentException;

final class AiProviderCatalog
{
    /** @var array<string, array{label: string, text_generation: bool, structured_output: bool, modalities: list<string>, embeddings: bool, reranking: bool, transcription: bool, discovery: string, selectable: bool}> */
    private const PROVIDERS = [
        'openai' => [
            'label' => 'OpenAI',
            'text_generation' => true,
            'structured_output' => true,
            'modalities' => ['image_input', 'document_input'],
            'embeddings' => false,
            'reranking' => false,
            'transcription' => false,
            'discovery' => 'curated',
            'selectable' => true,
        ],
        'azure' => [
            'label' => 'Azure OpenAI',
            'text_generation' => true,
            'structured_output' => true,
            'modalities' => ['image_input', 'document_input'],
            'embeddings' => false,
            'reranking' => false,
            'transcription' => false,
            'discovery' => 'deployment',
            'selectable' => true,
        ],
        'anthropic' => [
            'label' => 'Anthropic',
            'text_generation' => true,
            'structured_output' => true,
            'modalities' => ['image_input', 'document_input'],
            'embeddings' => false,
            'reranking' => false,
            'transcription' => false,
            'discovery' => 'curated',
            'selectable' => true,
        ],
        'gemini' => [
            'label' => 'Google Gemini',
            'text_generation' => true,
            'structured_output' => true,
            'modalities' => ['image_input', 'document_input'],
            'embeddings' => false,
            'reranking' => false,
            'transcription' => false,
            'discovery' => 'curated',
            'selectable' => true,
        ],
        'openrouter' => [
            'label' => 'OpenRouter',
            'text_generation' => true,
            'structured_output' => true,
            'modalities' => ['image_input', 'document_input'],
            'embeddings' => false,
            'reranking' => false,
            'transcription' => false,
            'discovery' => 'official_api',
            'selectable' => true,
        ],
        'xai' => [
            'label' => 'xAI',
            'text_generation' => true,
            'structured_output' => true,
            'modalities' => ['image_input'],
            'embeddings' => false,
            'reranking' => false,
            'transcription' => false,
            'discovery' => 'curated',
            'selectable' => true,
        ],
        'bedrock' => [
            'label' => 'Amazon Bedrock',
            'text_generation' => true,
            'structured_output' => true,
            'modalities' => ['image_input', 'document_input'],
            'embeddings' => false,
            'reranking' => false,
            'transcription' => false,
            'discovery' => 'deployment',
            'selectable' => true,
        ],
        'openai_compatible' => [
            'label' => 'OpenAI-compatible',
            'text_generation' => true,
            'structured_output' => false,
            'modalities' => ['image_input'],
            'embeddings' => false,
            'reranking' => false,
            'transcription' => false,
            'discovery' => 'official_api',
            'selectable' => true,
        ],
        'groq' => [
            'label' => 'Groq',
            'text_generation' => true,
            'structured_output' => true,
            'modalities' => [],
            'embeddings' => false,
            'reranking' => false,
            'transcription' => false,
            'discovery' => 'curated',
            'selectable' => true,
        ],
        'deepseek' => [
            'label' => 'DeepSeek',
            'text_generation' => true,
            'structured_output' => true,
            'modalities' => [],
            'embeddings' => false,
            'reranking' => false,
            'transcription' => false,
            'discovery' => 'curated',
            'selectable' => true,
        ],
        'ollama' => [
            'label' => 'Ollama',
            'text_generation' => true,
            'structured_output' => false,
            'modalities' => ['image_input'],
            'embeddings' => false,
            'reranking' => false,
            'transcription' => false,
            'discovery' => 'local_api',
            'selectable' => true,
        ],
        'mistral' => [
            'label' => 'Mistral',
            'text_generation' => true,
            'structured_output' => true,
            'modalities' => ['image_input'],
            'embeddings' => false,
            'reranking' => false,
            'transcription' => false,
            'discovery' => 'curated',
            'selectable' => true,
        ],
        'cohere' => [
            'label' => 'Cohere · Embeddings / reranking',
            'text_generation' => false,
            'structured_output' => false,
            'modalities' => [],
            'embeddings' => false,
            'reranking' => false,
            'transcription' => false,
            'discovery' => 'specialized',
            'selectable' => false,
        ],
        'jina' => [
            'label' => 'Jina AI · Embeddings / reranking',
            'text_generation' => false,
            'structured_output' => false,
            'modalities' => [],
            'embeddings' => false,
            'reranking' => false,
            'transcription' => false,
            'discovery' => 'specialized',
            'selectable' => false,
        ],
        'voyageai' => [
            'label' => 'Voyage AI · Embeddings / reranking',
            'text_generation' => false,
            'structured_output' => false,
            'modalities' => [],
            'embeddings' => false,
            'reranking' => false,
            'transcription' => false,
            'discovery' => 'specialized',
            'selectable' => false,
        ],
        'eleven' => [
            'label' => 'ElevenLabs · Audio / transcription',
            'text_generation' => false,
            'structured_output' => false,
            'modalities' => [],
            'embeddings' => false,
            'reranking' => false,
            'transcription' => false,
            'discovery' => 'specialized',
            'selectable' => false,
        ],
    ];

    /** @return array<string, string> */
    public static function options(?string $includeProvider = null): array
    {
        $includeProvider = $includeProvider === null ? null : strtolower(trim($includeProvider));
        $options = [];

        foreach (self::PROVIDERS as $key => $provider) {
            if ($provider['selectable'] || $key === $includeProvider) {
                $options[$key] = $provider['label'];
            }
        }

        return $options;
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
        if ($providerName === 'openai-compatible') {
            $providerName = 'openai_compatible';
        }

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

    /** @return array<string, bool|string|list<string>> */
    public static function capabilities(mixed $providerName): array
    {
        $provider = self::PROVIDERS[self::normalize($providerName)];

        return [
            'text_generation' => $provider['text_generation'],
            'structured_output' => $provider['structured_output'],
            'image_input' => in_array('image_input', $provider['modalities'], true),
            'document_input' => in_array('document_input', $provider['modalities'], true),
            'embeddings' => $provider['embeddings'],
            'reranking' => $provider['reranking'],
            'transcription' => $provider['transcription'],
            'discovery' => $provider['discovery'],
        ];
    }

    public static function isSpecialized(mixed $providerName): bool
    {
        return self::PROVIDERS[self::normalize($providerName)]['discovery'] === 'specialized';
    }

    public static function isSelectableForNewConfiguration(mixed $providerName): bool
    {
        return self::PROVIDERS[self::normalize($providerName)]['selectable'];
    }

    public static function discoveryStrategy(mixed $providerName): string
    {
        return self::PROVIDERS[self::normalize($providerName)]['discovery'];
    }
}
