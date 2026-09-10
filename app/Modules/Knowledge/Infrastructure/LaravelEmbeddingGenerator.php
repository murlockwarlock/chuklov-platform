<?php

namespace App\Modules\Knowledge\Infrastructure;

use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Registry\AiProviderCatalog;
use App\Modules\AI\Infrastructure\Providers\AiProviderExecutionConfiguration;
use App\Modules\AI\Infrastructure\Providers\AiProviderFactory;
use App\Modules\Knowledge\Domain\Contracts\EmbeddingGenerator;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingConfiguration;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use RuntimeException;

final class LaravelEmbeddingGenerator implements EmbeddingGenerator
{
    public function __construct(
        private readonly AiProviderFactory $providerFactory,
    ) {}

    public function generate(int $organizationId, array $inputs, EmbeddingConfiguration $configuration): array
    {
        $providerName = AiProviderCatalog::normalize($configuration->provider);
        $providerConfiguration = AiProviderConfiguration::query()
            ->where('organization_id', $organizationId)
            ->where('provider_name', $providerName)
            ->where('is_enabled', true)
            ->with('credential')
            ->first();

        if ($providerConfiguration === null
            || $providerConfiguration->health_status !== ProviderHealthStatus::Healthy) {
            throw new RuntimeException('Embedding provider configuration is unavailable.');
        }

        $credential = $providerConfiguration->credential;
        $credentiallessProvider = ! AiProviderExecutionConfiguration::providerRequiresSecret($providerName);
        if ($credential === null) {
            if (! $credentiallessProvider || $providerConfiguration->tested_credential_revision !== null) {
                throw new RuntimeException('Embedding provider credential is unavailable.');
            }
        } elseif ((int) $credential->organization_id !== $organizationId
            || $credential->provider !== $providerName
            || $credential->status !== CredentialStatus::Active
            || $credential->revision_id === null
            || $providerConfiguration->tested_credential_revision !== $credential->revision_id) {
            throw new RuntimeException('Embedding provider credential is unavailable.');
        }

        $configurationDigest = AiProviderExecutionConfiguration::digest(
            $providerName,
            (array) ($providerConfiguration->options ?? []),
        );
        if ($providerConfiguration->tested_configuration_digest !== $configurationDigest) {
            throw new RuntimeException('Embedding provider configuration is stale.');
        }

        $provider = $this->providerFactory->createEmbeddingProvider(
            providerName: $providerName,
            credential: $credential,
            extraConfig: ['provider_options' => (array) ($providerConfiguration->options ?? [])],
        );
        $response = $provider->embeddings(
            $inputs,
            $configuration->dimensions,
            $configuration->model,
            $configuration->timeoutSeconds,
        );

        if (count($response->embeddings) !== count($inputs)) {
            throw new RuntimeException('Embedding provider returned an unexpected result count.');
        }

        foreach ($response->embeddings as $embedding) {
            if (count($embedding) !== $configuration->dimensions) {
                throw new RuntimeException('Embedding provider returned incompatible dimensions.');
            }
        }

        return array_values(array_map(static fn (array $embedding): array => array_values($embedding), $response->embeddings));
    }
}
