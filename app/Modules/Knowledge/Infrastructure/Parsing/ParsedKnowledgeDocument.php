<?php

namespace App\Modules\Knowledge\Infrastructure\Parsing;

use App\Modules\Knowledge\Domain\Enums\KnowledgeExtractionStatus;

final readonly class ParsedKnowledgeDocument
{
    /** @param array<string, mixed> $diagnostics */
    public function __construct(
        public KnowledgeExtractionStatus $status,
        public ?string $content,
        public string $mimeType,
        public string $originalChecksum,
        public string $parserType,
        public string $parserVersion,
        public array $diagnostics,
    ) {}

    public function isReady(): bool
    {
        return $this->status === KnowledgeExtractionStatus::Ready
            && is_string($this->content)
            && trim($this->content) !== '';
    }
}
