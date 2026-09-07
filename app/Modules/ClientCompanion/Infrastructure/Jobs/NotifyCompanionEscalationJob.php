<?php

namespace App\Modules\ClientCompanion\Infrastructure\Jobs;

use App\Modules\ClientCompanion\Application\Notifications\NotifyCompanionEscalation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class NotifyCompanionEscalationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $escalationId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(NotifyCompanionEscalation $notifier): void
    {
        $notifier->handle($this->organizationId, $this->escalationId);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return [
            'companion-escalation:'.$this->escalationId,
            'organization:'.$this->organizationId,
        ];
    }
}
