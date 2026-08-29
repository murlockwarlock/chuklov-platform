<?php

namespace App\Modules\Knowledge\Jobs;

use App\Modules\Knowledge\Application\ProcessKnowledgeStorageCleanupOperation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessKnowledgeStorageCleanup implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $operationId,
    ) {}

    public function handle(ProcessKnowledgeStorageCleanupOperation $processor): void
    {
        $processor->handle($this->organizationId, $this->operationId);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['knowledge-storage-cleanup:'.$this->operationId];
    }
}
