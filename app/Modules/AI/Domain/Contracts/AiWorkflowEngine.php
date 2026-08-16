<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Application\Data\AiRunResult;

interface AiWorkflowEngine
{
    public function run(int $organizationId, AiRunRequest $request): AiRunResult;

    public function executeRun(int $organizationId, int $runId, string $workerLeaseToken): AiRunResult;
}
