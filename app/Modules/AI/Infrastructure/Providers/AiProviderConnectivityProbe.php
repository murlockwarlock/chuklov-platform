<?php

namespace App\Modules\AI\Infrastructure\Providers;

use App\Modules\AI\Domain\Exceptions\AiProviderProbeUnsupportedException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class AiProviderConnectivityProbe
{
    /** @param array<string, mixed> $options */
    public function probe(string $providerName, string $secret, array $options = []): void
    {
        if (trim($secret) === '') {
            throw new RuntimeException('Provider credential is empty.');
        }

        $driver = strtolower($providerName);
        $request = Http::acceptJson()->connectTimeout(3)->timeout(5);

        $response = match ($driver) {
            'openai' => $request->withToken($secret)->get($this->url($options, 'https://api.openai.com/v1/models')),
            'groq' => $request->withToken($secret)->get($this->url($options, 'https://api.groq.com/openai/v1/models')),
            'deepseek' => $request->withToken($secret)->get($this->url($options, 'https://api.deepseek.com/models')),
            'mistral' => $request->withToken($secret)->get($this->url($options, 'https://api.mistral.ai/v1/models')),
            'xai' => $request->withToken($secret)->get($this->url($options, 'https://api.x.ai/v1/models')),
            'openrouter' => $request->withToken($secret)->get($this->url($options, 'https://openrouter.ai/api/v1/models')),
            'anthropic' => $request->withHeaders([
                'x-api-key' => $secret,
                'anthropic-version' => '2023-06-01',
            ])->get($this->url($options, 'https://api.anthropic.com/v1/models')),
            'gemini' => $request->get($this->url($options, 'https://generativelanguage.googleapis.com/v1beta/models'), [
                'key' => $secret,
            ]),
            default => throw new AiProviderProbeUnsupportedException('This provider does not expose a supported low-impact connectivity probe.'),
        };

        if (! $response->successful()) {
            throw new RuntimeException('Provider connectivity probe returned a non-success response.');
        }
    }

    /** @param array<string, mixed> $options */
    private function url(array $options, string $default): string
    {
        $configured = $options['base_url'] ?? $options['url'] ?? null;
        if (! is_string($configured) || trim($configured) === '') {
            return $default;
        }

        $configured = rtrim($configured, '/');
        if (! str_starts_with($configured, 'https://')) {
            throw new RuntimeException('Provider connectivity probe requires an HTTPS base URL.');
        }

        return str_ends_with($configured, '/models') ? $configured : $configured.'/models';
    }
}
