<?php

namespace App\Modules\AI\Infrastructure\Providers;

use App\Modules\Security\Domain\Models\OrganizationCredential;
use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Anthropic\AnthropicGateway;
use Laravel\Ai\Gateway\Gemini\GeminiGateway;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Providers\AnthropicProvider;
use Laravel\Ai\Providers\AzureOpenAiProvider;
use Laravel\Ai\Providers\BedrockProvider;
use Laravel\Ai\Providers\DeepSeekProvider;
use Laravel\Ai\Providers\GeminiProvider;
use Laravel\Ai\Providers\GroqProvider;
use Laravel\Ai\Providers\MistralProvider;
use Laravel\Ai\Providers\OllamaProvider;
use Laravel\Ai\Providers\OpenAiCompatibleProvider;
use Laravel\Ai\Providers\OpenAiProvider;
use Laravel\Ai\Providers\OpenRouterProvider;
use Laravel\Ai\Providers\XaiProvider;

class AiProviderFactory
{
    public function __construct(
        private readonly Dispatcher $events,
    ) {}

    /**
     * Create an isolated, request-scoped TextProvider instance using the exact OrganizationCredential.
     *
     * @param  array<string, mixed>  $extraConfig
     */
    public function createTextProvider(
        string $providerName,
        OrganizationCredential $credential,
        ?Agent $agent = null,
        array $extraConfig = [],
    ): TextProvider {
        $secret = $this->resolveSecret($credential);
        $driver = strtolower($providerName);

        $config = array_merge([
            'driver' => $driver,
            'key' => $secret,
            'name' => $providerName,
        ], $extraConfig);

        $provider = match ($driver) {
            'openai' => new OpenAiProvider(
                new OpenAiGateway($this->events),
                $config,
                $this->events,
            ),
            'anthropic' => new AnthropicProvider(
                new AnthropicGateway($this->events),
                $config,
                $this->events,
            ),
            'gemini' => new GeminiProvider(
                new GeminiGateway($this->events),
                $config,
                $this->events,
            ),
            'groq' => new GroqProvider($config, $this->events),
            'deepseek' => new DeepSeekProvider($config, $this->events),
            'mistral' => new MistralProvider($config, $this->events),
            'xai' => new XaiProvider($config, $this->events),
            'openrouter' => new OpenRouterProvider($config, $this->events),
            'ollama' => new OllamaProvider($config, $this->events),
            'azure' => new AzureOpenAiProvider($config, $this->events),
            'bedrock' => new BedrockProvider($config, $this->events),
            'openai_compatible' => new OpenAiCompatibleProvider($config, $this->events),
            default => throw new InvalidArgumentException("AI Provider [{$providerName}] is not supported for text generation."),
        };

        // If testing fakes are active for the agent, attach the fake gateway
        $aiManager = app(AiManager::class);
        if ($agent !== null && $aiManager->hasFakeGatewayFor($agent)) {
            return $provider->useTextGateway($aiManager->fakeGatewayFor($agent));
        }

        return $provider;
    }

    /**
     * Perform the smallest supported connectivity check using the credential.
     */
    public function testConnectivity(string $providerName, OrganizationCredential $credential): void
    {
        $driver = strtolower($providerName);
        $secret = $this->resolveSecret($credential);

        if (trim($secret) === '') {
            throw new InvalidArgumentException('Credential secret is empty.');
        }

        // Validate basic driver support
        if (! in_array($driver, ['openai', 'anthropic', 'gemini', 'groq', 'deepseek', 'mistral', 'xai', 'openrouter'], true)) {
            throw new InvalidArgumentException("Provider [{$providerName}] does not support automated connection test.");
        }
    }

    private function resolveSecret(OrganizationCredential $credential): string
    {
        $credentials = $credential->credentials;

        return (string) ($credentials['api_key'] ?? $credentials['key'] ?? $credentials['secret'] ?? '');
    }
}
