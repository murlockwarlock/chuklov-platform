<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Application\Data\AiRunResult;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;

class ExecuteAiRun
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly AiWorkflowEngine $workflowEngine,
    ) {}

    public function handle(?User $actor, AiRunRequest $request): AiRunResult
    {
        $organization = $this->context->organization();

        if ($actor !== null) {
            $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewAiRuns);
        }

        return $this->workflowEngine->run($organization->getKey(), $request->withActor($actor));
    }
}
