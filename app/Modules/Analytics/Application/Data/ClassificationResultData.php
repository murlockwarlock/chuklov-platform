<?php

namespace App\Modules\Analytics\Application\Data;

use App\Modules\Analytics\Domain\Enums\ClassificationRunStatus;

final readonly class ClassificationResultData
{
    /** @param array<int, array{id: int|null, label: string, candidate_type?: string}> $suggestions */
    public function __construct(
        public int $runId,
        public ClassificationRunStatus $status,
        public array $suggestions,
        public ?string $errorCode = null,
    ) {}
}
