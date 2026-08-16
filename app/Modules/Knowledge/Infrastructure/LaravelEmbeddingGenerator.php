<?php

namespace App\Modules\Knowledge\Infrastructure;

use App\Modules\Knowledge\Domain\Contracts\EmbeddingGenerator;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingConfiguration;
use Laravel\Ai\Embeddings;
use RuntimeException;

final class LaravelEmbeddingGenerator implements EmbeddingGenerator
{
    public function generate(array $inputs, EmbeddingConfiguration $configuration): array
    {
        $response = Embeddings::for($inputs)
            ->dimensions($configuration->dimensions)
            ->timeout($configuration->timeoutSeconds)
            ->generate($configuration->provider, $configuration->model);

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
