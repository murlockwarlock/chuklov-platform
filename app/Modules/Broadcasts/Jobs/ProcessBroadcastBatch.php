<?php

namespace App\Modules\Broadcasts\Jobs;

use App\Modules\Broadcasts\Application\ProcessBroadcastBatch as Processor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessBroadcastBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $organizationId, public readonly int $batchId)
    {
        $this->onQueue('broadcasts');
    }

    public function handle(Processor $processor): void
    {
        if ($processor->handle($this->organizationId, $this->batchId)) {
            $this->release(300);
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['broadcasts', 'organization:'.$this->organizationId, 'broadcast-batch:'.$this->batchId];
    }
}
