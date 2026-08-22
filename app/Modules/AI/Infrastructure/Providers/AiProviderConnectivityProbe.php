<?php

namespace App\Modules\AI\Infrastructure\Providers;

use App\Modules\AI\Domain\Exceptions\AiProviderProbeUnsupportedException;
use App\Modules\AI\Domain\Registry\AiProviderCatalog;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class AiProviderConnectivityProbe
{
    /** @param array<string, mixed> $options */
    public function probe(string $providerName, string $secret, array $options = []): void
    {
        $driver = AiProviderCatalog::normalize($providerName);
        AiProviderExecutionConfiguration::assertSupportedOptions($options, $driver);
        if (AiProviderExecutionConfiguration::providerRequiresSecret($driver) && trim($secret) === '') {
            throw new RuntimeException('Provider credential is empty.');
        }

        $request = Http::withoutRedirecting()->acceptJson()->connectTimeout(3)->timeout(5);
        if (trim($secret) !== '') {
            $request = $request->withToken($secret);
        }

        $response = match ($driver) {
            'openai', 'groq', 'deepseek', 'mistral', 'xai', 'openrouter' => $request->get($this->canonicalEndpoint($driver)),
            'anthropic' => $request->withHeaders([
                'x-api-key' => $secret,
                'anthropic-version' => '2023-06-01',
            ])->get($this->canonicalEndpoint($driver)),
            'gemini' => $request->get($this->canonicalEndpoint($driver), [
                'key' => $secret,
            ]),
            'azure' => $request->withHeaders(['api-key' => $secret])->get(
                AiProviderExecutionConfiguration::probeEndpoint($driver, $options),
            ),
            'openai_compatible', 'ollama' => $request->get(
                AiProviderExecutionConfiguration::probeEndpoint($driver, $options),
            ),
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
