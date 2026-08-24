<?php

namespace App\Modules\Channels\Domain\ValueObjects;

final readonly class CompanionOutboundChunk
{
    public function __construct(
        public string $recipientExternalId,
        public string $semanticText,
        public int $chunkIndex,
        public int $chunkCount,
        public string $locale,
        /** @var list<CompanionActionButton> */
        public array $buttons = [],
    ) {}
}
