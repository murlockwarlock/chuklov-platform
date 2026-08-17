<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Infrastructure\Providers\AiProviderFactory;
use App\Modules\AI\Infrastructure\Providers\BoundedBedrockProvider;
use App\Modules\AI\Infrastructure\Providers\BoundedBedrockTextGateway;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Exception\AwsException;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\AiManager;
use ReflectionMethod;
use Tests\TestCase;
use Throwable;

final class BoundedBedrockTransportTest extends TestCase
{
    public function test_application_bedrock_provider_uses_bounded_text_and_embedding_gateways(): void
    {
        $manager = app(AiManager::class);
        $textProvider = $manager->textProvider('bedrock');
        $embeddingProvider = $manager->embeddingProvider('bedrock');

        self::assertInstanceOf(BoundedBedrockProvider::class, $textProvider);
        self::assertInstanceOf(BoundedBedrockTextGateway::class, $textProvider->textGateway());
        self::assertInstanceOf(BoundedBedrockProvider::class, $embeddingProvider);
        self::assertInstanceOf(BoundedBedrockTextGateway::class, $embeddingProvider->embeddingGateway());
    }

    public function test_organization_credential_text_factory_uses_bounded_bedrock_provider(): void
    {
        $credential = new OrganizationCredential;
        $credential->credentials = ['api_key' => 'test-secret'];

        $provider = app(AiProviderFactory::class)->createTextProvider('bedrock', $credential);

        self::assertInstanceOf(BoundedBedrockProvider::class, $provider);
        self::assertInstanceOf(BoundedBedrockTextGateway::class, $provider->textGateway());
    }

    public function test_retryable_bedrock_text_transport_failure_has_one_attempt(): void
    {
        $attempts = 0;
        $client = $this->clientWithRetryableFailure($attempts);

        try {
            $client->converse([
                'modelId' => 'amazon.nova-lite-v1:0',
                'messages' => [[
                    'role' => 'user',
                    'content' => [['text' => 'hello']],
                ]],
                'inferenceConfig' => ['maxTokens' => 1],
            ]);
        } catch (Throwable) {
        }

        self::assertSame(1, $attempts);
    }

    public function test_retryable_bedrock_embedding_transport_failure_has_one_attempt(): void
    {
        $attempts = 0;
        $client = $this->clientWithRetryableFailure($attempts);

        try {
            $client->invokeModel([
                'modelId' => 'amazon.titan-embed-text-v2:0',
                'contentType' => 'application/json',
                'accept' => 'application/json',
                'body' => json_encode(['inputText' => 'hello'], JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable) {
        }

        self::assertSame(1, $attempts);
    }

    private function clientWithRetryableFailure(int &$attempts): BedrockRuntimeClient
    {
        $provider = new BoundedBedrockProvider([
            'driver' => 'bedrock',
            'name' => 'bedrock',
            'region' => 'us-east-1',
            'access_key_id' => 'test-access-key',
            'secret_access_key' => 'test-secret-key',
            'use_default_credential_provider' => false,
        ], app(Dispatcher::class));
        $gateway = new BoundedBedrockTextGateway;
        $method = new ReflectionMethod($gateway, 'createBedrockClient');
        $method->setAccessible(true);
        $client = $method->invoke($gateway, $provider, 2);

        self::assertFalse($client->getHandlerList()->hasMiddleware('retry'));
        $client->getHandlerList()->setHandler(function ($command, $request) use (&$attempts): RejectedPromise {
            $attempts++;

            return new RejectedPromise(new AwsException(
                'throttled',
                $command,
                [
                    'code' => 'ThrottlingException',
                    'response' => new Response(500),
                    'request' => $request,
                ],
            ));
        });

        return $client;
    }
}
