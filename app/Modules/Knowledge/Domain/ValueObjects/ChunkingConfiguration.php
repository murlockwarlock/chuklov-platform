<?php

namespace App\Modules\Knowledge\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class ChunkingConfiguration
{
    public function __construct(
        public string $strategy,
        public string $version,
        public int $targetCharacters,
        public int $maximumCharacters,
        public int $overlapCharacters,
    ) {
        if ($strategy === '' || $version === '' || $targetCharacters < 1 || $maximumCharacters < $targetCharacters || $overlapCharacters >= $targetCharacters) {
            throw new InvalidArgumentException('Chunking configuration is invalid.');
        }
    }

    public static function active(): self
    {
        return new self(
            (string) config('rag.chunking.strategy'),
            (string) config('rag.chunking.version'),
            (int) config('rag.chunking.target_characters'),
            (int) config('rag.chunking.maximum_characters'),
            (int) config('rag.chunking.overlap_characters'),
        );
    }

    public function key(): string
    {
        return hash('sha256', implode('|', [$this->strategy, $this->version, $this->targetCharacters, $this->maximumCharacters, $this->overlapCharacters]));
    }
}
