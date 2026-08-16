<?php

namespace App\Modules\Knowledge\Domain\ValueObjects;

final readonly class ChunkData
{
    public function __construct(
        public int $index,
        public string $content,
        public int $startOffset,
        public int $endOffset,
        public ?string $sourceReference,
    ) {}
}
