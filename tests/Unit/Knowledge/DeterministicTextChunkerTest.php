<?php

namespace Tests\Unit\Knowledge;

use App\Modules\Knowledge\Application\DeterministicTextChunker;
use App\Modules\Knowledge\Domain\ValueObjects\ChunkingConfiguration;
use PHPUnit\Framework\TestCase;

final class DeterministicTextChunkerTest extends TestCase
{
    public function test_chunking_is_deterministic_bounded_and_preserves_offsets(): void
    {
        $configuration = new ChunkingConfiguration('normalized-character-window', 'v1', 30, 40, 5);
        $text = "First paragraph has useful words.\r\n\r\nSecond paragraph has more useful words for retrieval.";
        $chunker = new DeterministicTextChunker;

        $first = $chunker->chunk($text, $configuration, 'guide#one');
        $second = $chunker->chunk($text, $configuration, 'guide#one');

        self::assertEquals($first, $second);
        self::assertGreaterThan(1, count($first));
        foreach ($first as $index => $chunk) {
            self::assertSame($index, $chunk->index);
            self::assertLessThanOrEqual(40, mb_strlen($chunk->content));
            self::assertGreaterThan($chunk->startOffset, $chunk->endOffset);
            self::assertSame('guide#one', $chunk->sourceReference);
        }
    }
}
