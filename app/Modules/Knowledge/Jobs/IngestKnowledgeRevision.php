<?php

namespace App\Modules\Knowledge\Jobs;

use App\Modules\Knowledge\Application\ProcessKnowledgeIngestion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class IngestKnowledgeRevision implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly int $organizationId,
        public readonly int $sourceId,
        public readonly int $revisionId,
    ) {}

    public function handle(ProcessKnowledgeIngestion $ingestion): void
    {
        $ingestion->handle($this->organizationId, $this->sourceId, $this->revisionId);
    }
}
