<?php

namespace App\Modules\ClientCompanion\Infrastructure\Jobs;

use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use App\Modules\ClientCompanion\Application\Services\CompanionTurnProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessCompanionTurn implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout;

    public function __construct(public readonly int $organizationId, public readonly int $turnId)
    {
        $this->timeout = AiRuntimeLimits::PLATFORM_QUEUE_JOB_TIMEOUT_SECONDS;
        $this->onQueue('ai-companion');
    }

    public function handle(CompanionTurnProcessor $processor): void
    {
        $processor->handle($this->organizationId, $this->turnId);
    }

    public function failed(\Throwable $exception): void
    {
        app(CompanionTurnProcessor::class)->handleFailureFromQueue($this->organizationId, $this->turnId);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['companion-turn:'.$this->turnId, 'organization:'.$this->organizationId];
    }
}
