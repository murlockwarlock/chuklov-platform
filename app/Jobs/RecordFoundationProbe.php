<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class RecordFoundationProbe implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $probeId) {}

    public function handle(): void
    {
        Cache::put("foundation-probe:{$this->probeId}", true, 60);
    }
}
