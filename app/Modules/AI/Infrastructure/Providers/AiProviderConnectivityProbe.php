<?php

namespace App\Modules\AI\Infrastructure\Providers;

use App\Modules\AI\Domain\Enums\AiErrorCategory;
use App\Modules\AI\Domain\Exceptions\AiProviderProbeException;
use App\Modules\AI\Domain\Exceptions\AiProviderProbeUnsupportedException;
use App\Modules\AI\Domain\Registry\AiProviderCatalog;
use Illuminate\Http\Client\ConnectionException;
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

        $request = Http::withoutRedirecting()
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(5)
            ->withHeaders(['X-Chuklov-AI-Provider' => $driver]);
        if (trim($secret) !== '' && ! in_array($driver, ['anthropic', 'azure', 'gemini'], true)) {
            $request = $request->withToken($secret);
        }

        try {
            $response = match ($driver) {
                'openai', 'groq', 'deepseek', 'mistral', 'xai', 'openrouter' => $request->get($this->canonicalEndpoint($driver)),
                'anthropic' => $request->withHeaders([
                    'x-api-key' => $secret,
                    'anthropic-version' => '2023-06-01',
                ])->get($this->canonicalEndpoint($driver)),
                'gemini' => $request->withHeaders(['x-goog-api-key' => $secret])->get($this->canonicalEndpoint($driver)),
                'azure' => $request->withHeaders(['api-key' => $secret])->get(
                    AiProviderExecutionConfiguration::probeEndpoint($driver, $options),
                ),
                'openai_compatible', 'ollama' => $request->get(
                    AiProviderExecutionConfiguration::probeEndpoint($driver, $options),
                ),
                default => throw new AiProviderProbeUnsupportedException('This provider does not expose a supported low-impact connectivity probe.'),
            };
        } catch (ConnectionException $exception) {
            $category = preg_match('/timeout|timed out|deadline exceeded/i', $exception->getMessage()) === 1
                ? AiErrorCategory::ExecutionTimedOut
                : AiErrorCategory::ProviderUnavailable;

            throw new AiProviderProbeException($category, previous: $exception);
        }

        if (! $response->successful()) {
            $status = $response->status();
            $category = match (true) {
                in_array($status, [401, 403], true) => AiErrorCategory::AuthenticationFailed,
                $status === 429 => AiErrorCategory::RateLimited,
                $status >= 500 => AiErrorCategory::ProviderUnavailable,
                default => AiErrorCategory::InternalError,
            };

            throw new AiProviderProbeException($category, $status);
        }
    }

    private function canonicalEndpoint(string $providerName): string
    {
        return AiProviderExecutionConfiguration::canonicalProbeEndpoint($providerName)
            ?? throw new AiProviderProbeUnsupportedException('This provider does not expose a supported low-impact connectivity probe.');
    }
}
