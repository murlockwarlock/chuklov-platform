<?php

namespace App\Modules\Knowledge\Application\Data;

final readonly class KnowledgeRevisionDownloadResult
{
    public function __construct(
        public mixed $stream,
        public string $filename,
        public string $mimeType,
        public int $sizeBytes,
    ) {}
}
