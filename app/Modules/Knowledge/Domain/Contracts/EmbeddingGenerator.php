<?php

namespace App\Modules\Knowledge\Domain\Contracts;

use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingConfiguration;

interface EmbeddingGenerator
{
    /**
     * @param  list<string>  $inputs
     * @return list<list<float>>
     */
    public function generate(array $inputs, EmbeddingConfiguration $configuration): array;
}
