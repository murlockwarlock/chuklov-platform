<?php

namespace App\Modules\Knowledge\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class EmbeddingConfiguration
{
    public function __construct(
        public string $provider,
        public string $model,
        public int $dimensions,
        public string $version,
        public int $timeoutSeconds,
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
        );
    }

    public function key(): string
    {
        return hash('sha256', implode('|', [$this->provider, $this->model, $this->dimensions, $this->version]));
    }
}
