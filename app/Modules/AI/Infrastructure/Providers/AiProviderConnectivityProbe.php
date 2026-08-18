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
        AiProviderExecutionConfiguration::assertSupportedOptions($options);
        $request = Http::withoutRedirecting()->acceptJson()->connectTimeout(3)->timeout(5);

        $response = match ($driver) {
            'openai' => $request->withToken($secret)->get($this->canonicalEndpoint($driver)),
            'groq' => $request->withToken($secret)->get($this->canonicalEndpoint($driver)),
            'deepseek' => $request->withToken($secret)->get($this->canonicalEndpoint($driver)),
            'mistral' => $request->withToken($secret)->get($this->canonicalEndpoint($driver)),
            'xai' => $request->withToken($secret)->get($this->canonicalEndpoint($driver)),
            'openrouter' => $request->withToken($secret)->get($this->canonicalEndpoint($driver)),
            'anthropic' => $request->withHeaders([
                'x-api-key' => $secret,
                'anthropic-version' => '2023-06-01',
            ])->get($this->canonicalEndpoint($driver)),
            'gemini' => $request->get($this->canonicalEndpoint($driver), [
                'key' => $secret,
            ]),
            default => throw new AiProviderProbeUnsupportedException('This provider does not expose a supported low-impact connectivity probe.'),
        };

        if (! $response->successful()) {
            throw new RuntimeException('Provider connectivity probe returned a non-success response.');
        }
    }

    private function canonicalEndpoint(string $providerName): string
    {
        return AiProviderExecutionConfiguration::canonicalProbeEndpoint($providerName)
            ?? throw new AiProviderProbeUnsupportedException('This provider does not expose a supported low-impact connectivity probe.');
    }
}
