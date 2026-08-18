<?php

namespace App\Modules\AI\Infrastructure\Providers;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Middleware;
use Laravel\Ai\Gateway\Bedrock\BedrockTextGateway;
use Laravel\Ai\Providers\Provider;
use Psr\Http\Message\RequestInterface;

final class BoundedBedrockTextGateway extends BedrockTextGateway
{
    protected function createBedrockClient(Provider $provider, ?int $timeout = null): BedrockRuntimeClient
    {
        $credentials = $provider->providerCredentials();
        $configuration = $provider->additionalConfiguration();
        $clientConfiguration = [
            'region' => $this->bedrockRegion($configuration),
            'version' => '2023-09-30',
            ...$this->resolveAuthConfig($credentials, $configuration, $timeout),
            'retries' => 0,
        ];

        if ($timeout) {
            $clientConfiguration['http'] = ['timeout' => $timeout];
        }

        $client = new BedrockRuntimeClient($clientConfiguration);

        if ($headers = $configuration['headers'] ?? []) {
            $client->getHandlerList()->appendBuild(Middleware::mapRequest(
                function (RequestInterface $request) use ($headers): RequestInterface {
                    foreach ($headers as $name => $value) {
                        $request = $request->withHeader($name, $value);
                    }

                    return $request;
                },
            ), 'laravel-ai.headers');
        }

        return $client;
    }
}
