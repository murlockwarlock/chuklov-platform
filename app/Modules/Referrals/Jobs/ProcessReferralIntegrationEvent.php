<?php

namespace App\Modules\Referrals\Jobs;

use App\Modules\Referrals\Application\ConsumeFinanceSettlementEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessReferralIntegrationEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $integrationEventId) {}

    public function handle(ConsumeFinanceSettlementEvent $consumer): void
    {
        $consumer->handle($this->integrationEventId);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['integration-event:'.$this->integrationEventId];
    }
}
