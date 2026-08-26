<?php

namespace App\Modules\Broadcasts\Jobs;

use App\Modules\Broadcasts\Application\ProcessBroadcastBatch as Processor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

final class ProcessBroadcastBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public readonly string $leaseToken;

    public function __construct(public readonly int $organizationId, public readonly int $batchId, ?string $leaseToken = null)
    {
        $this->leaseToken = $leaseToken === null || $leaseToken === '' ? (string) Str::uuid() : $leaseToken;
        $this->onQueue('broadcasts');
    }

    public function handle(Processor $processor): void
    {
        if ($processor->handle($this->organizationId, $this->batchId, $this->leaseToken)) {
            $this->release(300);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(Processor::class)->markJobFailed(
            $this->organizationId,
            $this->batchId,
            $exception === null ? null : 'queue_job_failed',
            $this->leaseToken,
        );
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['broadcasts', 'organization:'.$this->organizationId, 'broadcast-batch:'.$this->batchId];
    }
}
