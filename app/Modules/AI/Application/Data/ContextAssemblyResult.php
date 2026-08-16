<?php

namespace App\Modules\AI\Application\Data;

use App\Modules\Knowledge\Application\Data\RetrievalResult;

final readonly class ContextAssemblyResult
{
    /**
     * @param  array<string, mixed>  $variables
     * @param  list<RetrievalResult>  $ragChunks
     * @param  array<string, mixed>  $provenanceSummary
     */
    public function __construct(
        public array $variables,
        public array $ragChunks = [],
        public array $provenanceSummary = [],
    ) {}
}
