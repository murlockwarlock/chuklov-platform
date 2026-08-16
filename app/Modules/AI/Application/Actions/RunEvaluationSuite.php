<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Models\AiEvalRun;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Carbon\Carbon;
use InvalidArgumentException;

class RunEvaluationSuite
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly AiWorkflowEngine $workflowEngine,
    ) {}

    public function handle(
        User $actor,
        int $evalSuiteId,
        int $promptVersionId,
        string $provider = 'openai',
        string $model = 'gpt-4o-mini',
    ): AiEvalRun {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageAiPrompts);

        $suite = AiEvalSuite::query()
            ->where('organization_id', $organization->getKey())
            ->where('id', $evalSuiteId)
            ->with('cases')
            ->first();

        if ($suite === null) {
            throw new InvalidArgumentException('Evaluation suite not found.');
        }

        $promptVersion = AiPromptVersion::query()
            ->where('organization_id', $organization->getKey())
            ->where('id', $promptVersionId)
            ->first();

        if ($promptVersion === null) {
            throw new InvalidArgumentException('Prompt version not found.');
        }

        $cases = $suite->cases()->where('is_active', true)->get();
        $totalCases = $cases->count();
        $passedCases = 0;
        $failedCases = 0;
        $caseResults = [];

        foreach ($cases as $case) {
            $request = new AiRunRequest(
                capability: $suite->capability,
                workflowKey: "eval_suite_{$suite->key}_case_{$case->id}",
                origin: AiRunOrigin::Evaluation,
                executionMode: AiExecutionMode::Evaluation,
                initiatedByUserId: $actor->getKey(),
                promptVersionId: $promptVersion->id,
                inputVariables: $case->test_inputs ?? [],
            );

            $result = $this->workflowEngine->run($organization->getKey(), $request);

            $passed = $result->isSuccess();
            $failureReason = null;

            if ($passed && ! empty($case->expected_assertions)) {
                foreach ((array) $case->expected_assertions as $assertionKey => $expectedVal) {
                    if ($assertionKey === 'contains_text' && is_string($expectedVal)) {
                        if (! str_contains(mb_strtolower((string) $result->outputText), mb_strtolower($expectedVal))) {
                            $passed = false;
                            $failureReason = "Output does not contain expected text: {$expectedVal}";
                            break;
                        }
                    }
                }
            }

            if ($passed) {
                $passedCases++;
            } else {
                $failedCases++;
                if ($failureReason === null) {
                    $failureReason = ($result->errorMessageSanitized ?? '').' (Status: '.$result->status->value.')';
                }
            }

            $caseResults[] = [
                'case_id' => $case->id,
                'case_name' => $case->name,
                'run_id' => $result->runId,
                'passed' => $passed,
                'latency_ms' => $result->latencyMs,
                'failure_reason' => $failureReason,
            ];
        }

        $evalRun = new AiEvalRun([
            'organization_id' => $organization->getKey(),
            'eval_suite_id' => $suite->id,
            'prompt_version_id' => $promptVersion->id,
            'provider' => $provider,
            'model' => $model,
            'total_cases' => $totalCases,
            'passed_cases' => $passedCases,
            'failed_cases' => $failedCases,
            'results_payload' => [
                'cases' => $caseResults,
                'executed_at' => Carbon::now()->toIso8601String(),
            ],
            'executed_by_user_id' => $actor->getKey(),
        ]);
        $evalRun->save();

        return $evalRun;
    }
}
