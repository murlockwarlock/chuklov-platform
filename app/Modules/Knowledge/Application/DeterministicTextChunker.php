<?php

namespace App\Modules\Knowledge\Application;

use App\Modules\Knowledge\Domain\ValueObjects\ChunkData;
use App\Modules\Knowledge\Domain\ValueObjects\ChunkingConfiguration;

final class DeterministicTextChunker
{
    /** @return list<ChunkData> */
    public function chunk(string $text, ChunkingConfiguration $configuration, ?string $sourceReference): array
    {
        $normalized = $this->normalize($text);
        $length = mb_strlen($normalized);
        $chunks = [];
        $offset = 0;

        while ($offset < $length) {
            $remaining = $length - $offset;
            $size = min($configuration->maximumCharacters, $remaining);
            $candidate = mb_substr($normalized, $offset, $size);

            if ($remaining > $configuration->maximumCharacters) {
                $minimumBreak = min($configuration->targetCharacters, mb_strlen($candidate));
                $breakAt = mb_strrpos(mb_substr($candidate, 0, $configuration->maximumCharacters), "\n", $minimumBreak);
                if ($breakAt === false) {
                    $breakAt = mb_strrpos(mb_substr($candidate, 0, $configuration->maximumCharacters), ' ', $minimumBreak);
                }
                if ($breakAt !== false && $breakAt >= $minimumBreak) {
                    $size = $breakAt;
                    $candidate = mb_substr($candidate, 0, $size);
                }
            }

            $content = trim($candidate);
            if ($content !== '') {
                $chunks[] = new ChunkData(count($chunks), $content, $offset, $offset + $size, $sourceReference);
            }

            if ($offset + $size >= $length) {
                break;
            }

            $offset += max(1, $size - $configuration->overlapCharacters);
        }

        return $chunks;
    }

    public function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\t ]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
