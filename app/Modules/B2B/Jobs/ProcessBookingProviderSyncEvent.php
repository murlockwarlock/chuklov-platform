<?php

namespace App\Modules\B2B\Jobs;

use App\Modules\B2B\Application\SyncBookingProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessBookingProviderSyncEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $integrationEventId)
    {
        $this->onConnection('redis');
    }

    public function handle(SyncBookingProvider $synchronizer): void
    {
        $synchronizer->handle($this->integrationEventId);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['booking-provider-event:'.$this->integrationEventId];
    }
}
