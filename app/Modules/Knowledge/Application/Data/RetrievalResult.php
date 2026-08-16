<?php

namespace App\Modules\Knowledge\Application\Data;

final readonly class RetrievalResult
{
    public function __construct(
        public int $chunkId,
        public int $sourceId,
        public string $sourceTitle,
        public string $sourceType,
        public int $revisionId,
        public int $revisionVersion,
        public int $chunkIndex,
        public string $content,
        public float $similarity,
        public ?string $sourceReference,
        public int $startOffset,
        public int $endOffset,
        public int $ingestionRunId,
        public string $embeddingConfigurationKey,
    ) {}
}
