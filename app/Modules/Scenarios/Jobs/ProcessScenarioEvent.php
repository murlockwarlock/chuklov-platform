<?php

namespace App\Modules\Scenarios\Jobs;

use App\Modules\Scenarios\Application\MaterializeScenarioEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessScenarioEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $scenarioEventId) {}

    public function handle(MaterializeScenarioEvent $materializer): void
    {
        $materializer->handle($this->scenarioEventId);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['scenario-event:'.$this->scenarioEventId];
    }
}
