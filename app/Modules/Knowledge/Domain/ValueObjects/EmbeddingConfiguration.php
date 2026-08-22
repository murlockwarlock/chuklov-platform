<?php

namespace App\Modules\Knowledge\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class EmbeddingConfiguration
{
    public const int MAX_QUERY_CHARACTERS = 4000;

    public const int MAX_QUERY_BYTES = self::MAX_QUERY_CHARACTERS * 4;

    public const int MAX_RUNTIME_TIMEOUT_SECONDS = 30;

    public function __construct(
        public string $provider,
        public string $model,
        public int $dimensions,
        public string $version,
        public int $timeoutSeconds,
        public ?string $catalogSource = null,
        public ?string $verifiedAsOf = null,
    ) {
        if ($provider === '' || $model === '' || $version === '' || $dimensions < 1 || $timeoutSeconds < 1) {
            throw new InvalidArgumentException('Embedding configuration is invalid.');
        }
    }

    public static function active(): self
    {
        return new self(
            (string) config('rag.embedding.provider'),
            (string) config('rag.embedding.model'),
            (int) config('rag.embedding.dimensions'),
            (string) config('rag.embedding.configuration_version'),
            (int) config('rag.embedding.timeout_seconds'),
            is_string(config('rag.embedding.catalog_source')) ? config('rag.embedding.catalog_source') : null,
            is_string(config('rag.embedding.verified_as_of')) ? config('rag.embedding.verified_as_of') : null,
        );
    }

    /** @param array<string, mixed> $snapshot */
    public static function fromArray(array $snapshot): self
    {
        $provider = $snapshot['provider'] ?? null;
        $model = $snapshot['model'] ?? null;
        $dimensions = $snapshot['dimensions'] ?? null;
        $version = $snapshot['configuration_version'] ?? null;
        $timeoutSeconds = $snapshot['timeout_seconds'] ?? null;
        $catalogSource = $snapshot['catalog_source'] ?? null;
        $verifiedAsOf = $snapshot['verified_as_of'] ?? null;

        if (! is_string($provider)
            || ! is_string($model)
            || ! is_int($dimensions)
            || ! is_string($version)
            || ! is_int($timeoutSeconds)) {
            throw new InvalidArgumentException('Embedding configuration snapshot is invalid.');
        }
        if ($catalogSource !== null && ! is_string($catalogSource)) {
            throw new InvalidArgumentException('Embedding configuration source is invalid.');
        }
        if ($verifiedAsOf !== null && ! is_string($verifiedAsOf)) {
            throw new InvalidArgumentException('Embedding configuration verification date is invalid.');
        }

        $configuration = new self($provider, $model, $dimensions, $version, $timeoutSeconds, $catalogSource, $verifiedAsOf);
        if (($snapshot['configuration_key'] ?? null) !== $configuration->key()) {
            throw new InvalidArgumentException('Embedding configuration snapshot key is invalid.');
        }

        return $configuration;
    }

    public function key(): string
    {
        return hash('sha256', implode('|', [$this->provider, $this->model, $this->dimensions, $this->version]));
    }

    public function assertSame(self $configuration): void
    {
        if ($this->provider !== $configuration->provider
            || $this->model !== $configuration->model
            || $this->dimensions !== $configuration->dimensions
            || $this->version !== $configuration->version
            || $this->key() !== $configuration->key()) {
            throw new InvalidArgumentException('Embedding configuration changed after AI run preparation.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'dimensions' => $this->dimensions,
            'configuration_version' => $this->version,
            'configuration_key' => $this->key(),
            'timeout_seconds' => $this->timeoutSeconds,
            'catalog_source' => $this->catalogSource,
            'verified_as_of' => $this->verifiedAsOf,
        ];
    }

    public function withTimeoutSeconds(int $timeoutSeconds): self
    {
        return new self(
            provider: $this->provider,
            model: $this->model,
            dimensions: $this->dimensions,
            version: $this->version,
            timeoutSeconds: $timeoutSeconds,
            catalogSource: $this->catalogSource,
            verifiedAsOf: $this->verifiedAsOf,
        );
    }
}
