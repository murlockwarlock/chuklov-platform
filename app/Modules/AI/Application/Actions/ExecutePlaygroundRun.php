<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Application\Data\AiRunResult;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;

class ExecutePlaygroundRun
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly AiWorkflowEngine $workflowEngine,
    ) {}

    /**
     * @param  array<string, mixed>  $inputVariables
     */
    public function handle(
        User $actor,
        AiCapability $capability,
        ?int $promptVersionId = null,
        array $inputVariables = [],
    ): AiRunResult {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::UseAiPlayground);

        $request = new AiRunRequest(
            capability: $capability,
            workflowKey: 'playground_test',
            origin: AiRunOrigin::Playground,
            executionMode: AiExecutionMode::Playground,
            initiatedByUserId: $actor->getKey(),
            promptVersionId: $promptVersionId,
            inputVariables: $inputVariables,
            actor: $actor,
        );

        return $this->workflowEngine->run($organization->getKey(), $request);
    }
}
