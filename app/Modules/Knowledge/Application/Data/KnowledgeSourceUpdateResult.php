<?php

namespace App\Modules\Knowledge\Application\Data;

use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;

final readonly class KnowledgeSourceUpdateResult
{
    public function __construct(
        public KnowledgeSource $source,
        public ?KnowledgeRevision $revision,
        public bool $revisionCreated,
    ) {}
}
