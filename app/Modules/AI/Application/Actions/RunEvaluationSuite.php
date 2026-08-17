<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Application\Validation\EvalInputPrivacyValidator;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Models\AiEvalRun;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use Carbon\Carbon;
use InvalidArgumentException;

class RunEvaluationSuite
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly AiWorkflowEngine $workflowEngine,
        private readonly EvalInputPrivacyValidator $privacyValidator,
    ) {}

    public function handle(
        User $actor,
        int $evalSuiteId,
        int $promptVersionId,
        ?int $modelReleaseId = null,
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

        if ($modelReleaseId === null) {
            throw new InvalidArgumentException('Evaluation execution requires an exact immutable model release.');
        }

        $promptVersion = AiPromptVersion::query()
            ->where('organization_id', $organization->getKey())
            ->where('id', $promptVersionId)
            ->first();

        if ($promptVersion === null) {
            throw new InvalidArgumentException('Prompt version not found.');
        }

        if ($promptVersion->prompt->capability !== $suite->capability) {
            throw new InvalidArgumentException('Pinned evaluation prompt version does not support the suite capability.');
        }

        if ($suite->prompt_id !== null && (int) $suite->prompt_id !== (int) $promptVersion->prompt_id) {
            throw new InvalidArgumentException('Evaluation prompt version does not belong to the evaluation suite prompt.');
        }

        if ($promptVersion->status->value === 'draft') {
            throw new InvalidArgumentException('Draft prompt versions cannot execute evaluations.');
        }

        $release = AiModelRelease::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($modelReleaseId)
            ->with(['modelConfiguration.providerConfiguration.credential'])
            ->first();

        if ($release === null || ! in_array($release->status, ['active', 'retired'], true)) {
            throw new InvalidArgumentException('Pinned evaluation model release is not available under the evaluation policy.');
        }

        if (! in_array($suite->capability->value, $release->capabilities, true)) {
            throw new InvalidArgumentException('Pinned evaluation model release does not support the suite capability.');
        }

        $modelConfiguration = $release->modelConfiguration;
        $providerConfiguration = $modelConfiguration->providerConfiguration;
        $credential = $providerConfiguration?->credential;
        if (! $modelConfiguration->is_enabled
            || $modelConfiguration->lifecycle_status->value === 'deprecated'
            || $providerConfiguration === null
            || $providerConfiguration->provider_name !== $release->provider_name
            || ! $providerConfiguration->is_enabled
            || $providerConfiguration->health_status !== ProviderHealthStatus::Healthy
            || $credential === null
            || $credential->provider !== $providerConfiguration->provider_name
            || $credential->status !== CredentialStatus::Active) {
            throw new InvalidArgumentException('Pinned evaluation model release is not backed by a valid executable provider configuration.');
        }

        $cases = $suite->cases()->where('is_active', true)->get();
        $totalCases = $cases->count();
        $passedCases = 0;
        $failedCases = 0;
        $caseResults = [];
        $actualProvider = null;
        $actualModel = null;

        foreach ($cases as $case) {
            $this->privacyValidator->validateClassification($case->is_synthetic, $case->is_deidentified);
            $this->privacyValidator->validate((array) $case->test_inputs);

            $request = new AiRunRequest(
                capability: $suite->capability,
                workflowKey: "eval_suite_{$suite->key}_case_{$case->id}",
                origin: AiRunOrigin::Evaluation,
                executionMode: AiExecutionMode::Evaluation,
                initiatedByUserId: $actor->getKey(),
                promptVersionId: $promptVersion->id,
                modelReleaseId: $release->id,
                inputVariables: $case->test_inputs ?? [],
                actor: $actor,
            );

            $result = $this->workflowEngine->run($organization->getKey(), $request);

            $passed = $result->isSuccess();
            $failureReason = null;
            $actualRun = AiRun::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($result->runId)
                ->first();
            if ($actualRun !== null) {
                $actualProvider ??= $actualRun->actual_provider;
                $actualModel ??= $actualRun->actual_model;
            }

            if ($actualRun !== null && (int) $actualRun->model_release_id !== (int) $release->id) {
                $passed = false;
                $failureReason = 'pinned_release_mismatch';
            }

            if ($passed && ! empty($case->expected_assertions)) {
                foreach ((array) $case->expected_assertions as $assertionKey => $expectedVal) {
                    if ($assertionKey === 'contains_text' && is_string($expectedVal)) {
                        if (! str_contains(mb_strtolower((string) $result->outputText), mb_strtolower($expectedVal))) {
                            $passed = false;
                            $failureReason = 'assertion_failed';
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
                    $failureReason = $result->errorCategory?->value;
                }
            }

            $caseResults[] = [
                'case_id' => $case->id,
                'run_id' => $result->runId,
                'passed' => $passed,
                'latency_ms' => $result->latencyMs,
                'failure_code' => $failureReason,
                'model_release_id' => $actualRun?->model_release_id,
                'actual_provider' => $actualRun?->actual_provider,
                'actual_model' => $actualRun?->actual_model,
            ];
        }

        $evalRun = new AiEvalRun([
            'organization_id' => $organization->getKey(),
            'eval_suite_id' => $suite->id,
            'prompt_version_id' => $promptVersion->id,
            'model_release_id' => $release->id,
            'provider' => $actualProvider ?? $release->provider_name,
            'model' => $actualModel ?? $release->model_name,
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
