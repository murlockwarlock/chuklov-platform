<?php

namespace App\Modules\Scenarios\Jobs;

use App\Modules\Scenarios\Application\ExecuteScenarioAction as ExecuteScenarioActionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ExecuteScenarioAction implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $scenarioActionId) {}

    public function handle(ExecuteScenarioActionService $executor): void
    {
        $executor->handle($this->scenarioActionId);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['scenario-action:'.$this->scenarioActionId];
    }
}
