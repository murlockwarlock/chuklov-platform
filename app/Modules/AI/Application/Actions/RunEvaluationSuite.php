<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Data\AiEvaluationCaseResult;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Application\Services\AiEvaluationRunMetricsAggregator;
use App\Modules\AI\Application\Services\AiEvaluationSnapshotHasher;
use App\Modules\AI\Application\Validation\EvalInputPrivacyValidator;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiErrorCategory;
use App\Modules\AI\Domain\Enums\AiEvaluationCaseStatus;
use App\Modules\AI\Domain\Enums\AiEvaluationCheckCategory;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Models\AiEvalCase;
use App\Modules\AI\Domain\Models\AiEvalRun;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunAttempt;
use App\Modules\AI\Domain\Models\AiRunRagReference;
use App\Modules\AI\Domain\Services\AiEvaluationAssertionRegistry;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use App\Modules\AI\Infrastructure\Providers\AiProviderExecutionConfiguration;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class RunEvaluationSuite
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly AiWorkflowEngine $workflowEngine,
        private readonly EvalInputPrivacyValidator $privacyValidator,
        private readonly AiEvaluationAssertionRegistry $assertionRegistry,
        private readonly AiEvaluationRunMetricsAggregator $metricsAggregator,
        private readonly AiEvaluationSnapshotHasher $snapshotHasher,
        private readonly RecordAuditEvent $audit,
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
            ->whereKey($evalSuiteId)
            ->first();

        if ($suite === null) {
            throw new InvalidArgumentException('Evaluation suite not found.');
        }

        if ($modelReleaseId === null) {
            throw new InvalidArgumentException('Evaluation execution requires an exact immutable model release.');
        }

        $promptVersion = AiPromptVersion::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($promptVersionId)
            ->first();

        if ($promptVersion === null) {
            throw new InvalidArgumentException('Prompt version not found.');
        }

        if ($promptVersion->prompt === null || $promptVersion->prompt->capability !== $suite->capability) {
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
            || $providerConfiguration->health_status->value !== 'healthy'
            || $credential === null
            || $credential->provider !== $providerConfiguration->provider_name
            || $credential->status !== CredentialStatus::Active
            || $credential->revision_id === null
            || $providerConfiguration->tested_credential_revision === null) {
            throw new InvalidArgumentException('Pinned evaluation model release is not backed by a valid executable provider configuration.');
        }

        try {
            $configurationDigest = AiProviderExecutionConfiguration::digest(
                $providerConfiguration->provider_name,
                $providerConfiguration->options ?? [],
            );
        } catch (\Throwable) {
            throw new InvalidArgumentException('Pinned evaluation provider configuration has no supported executable configuration.');
        }

        if ($providerConfiguration->tested_credential_revision !== $credential->revision_id
            || $providerConfiguration->tested_configuration_digest !== $configurationDigest) {
            throw new InvalidArgumentException('Pinned evaluation provider configuration requires a probe for the current credential and configuration.');
        }

        $cases = $suite->cases()
            ->where('is_active', true)
            ->orderBy('id')
            ->limit(AiRuntimeLimits::PLATFORM_MAX_EVALUATION_CASES + 1)
            ->get();
        if ($cases->count() > AiRuntimeLimits::PLATFORM_MAX_EVALUATION_CASES) {
            throw new InvalidArgumentException('Evaluation suite exceeds the platform maximum of '.AiRuntimeLimits::PLATFORM_MAX_EVALUATION_CASES.' active cases.');
        }

        $assertionsByCase = [];
        foreach ($cases as $case) {
            $this->privacyValidator->validateClassification($case->is_synthetic, $case->is_deidentified);
            $this->privacyValidator->validate((array) $case->test_inputs);
            $this->privacyValidator->validate((array) $case->expected_assertions);
            $assertionsByCase[$case->getKey()] = $this->assertionRegistry->normalize((array) $case->expected_assertions);
            if ($case->expected_output_schema !== null) {
                $this->privacyValidator->validate((array) $case->expected_output_schema);
                $this->assertionRegistry->validateSchema((array) $case->expected_output_schema);
            }
        }

        if ($suite->capability === AiCapability::PostureAnalysis) {
            throw new InvalidArgumentException('PostureAnalysis evaluation requires a controlled three-photo fixture.');
        }

        $executions = [];
        foreach ($cases as $case) {
            $result = $this->workflowEngine->run($organization->getKey(), new AiRunRequest(
                capability: $suite->capability,
                workflowKey: "eval_suite_{$suite->key}_case_{$case->id}",
                origin: AiRunOrigin::Evaluation,
                executionMode: AiExecutionMode::Evaluation,
                initiatedByUserId: $actor->getKey(),
                promptVersionId: $promptVersion->id,
                modelReleaseId: $release->id,
                inputVariables: $case->test_inputs ?? [],
                actor: $actor,
            ));
            $executions[] = [
                'case' => $case,
                'assertions' => $assertionsByCase[$case->getKey()] ?? [],
                'result' => $result,
            ];
        }

        $runIds = array_values(array_unique(array_map(static fn (array $execution): int => $execution['result']->runId, $executions)));
        $actualRuns = AiRun::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('id', $runIds)
            ->get()
            ->keyBy('id');
        $attempts = AiRunAttempt::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('ai_run_id', $runIds)
            ->orderBy('attempt_number')
            ->limit(max(1, count($runIds)) * AiRuntimeLimits::PLATFORM_MAX_FAILOVER_ATTEMPTS)
            ->get()
            ->groupBy('ai_run_id');
        $ragReferences = AiRunRagReference::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('ai_run_id', $runIds)
            ->where('reference_index', '<=', AiRuntimeLimits::PLATFORM_MAX_RAG_CHUNKS)
            ->with([
                'source:id,organization_id,title',
                'chunk:id,organization_id,source_reference',
            ])
            ->orderBy('reference_index')
            ->limit(max(1, count($runIds)) * AiRuntimeLimits::PLATFORM_MAX_RAG_CHUNKS)
            ->get()
            ->groupBy('ai_run_id');

        $caseResults = [];
        foreach ($executions as $execution) {
            /** @var AiEvalCase $case */
            $case = $execution['case'];
            $result = $execution['result'];
            $actualRun = $actualRuns->get($result->runId);
            $caseAttempts = $attempts->get($result->runId, collect());
            $references = $this->ragReferenceData($ragReferences->get($result->runId, collect()));
            $assertionResults = [];
            $status = AiEvaluationCaseStatus::ExecutionFailed;
            $failureCategory = AiEvaluationCheckCategory::Execution->value;
            $failureCode = $result->errorCategory instanceof AiErrorCategory
                ? $result->errorCategory->value
                : 'execution_failed';
            $failureExplanation = 'AI не смог выполнить этот пример.';

            if ($result->isSuccess() && $actualRun instanceof AiRun && $actualRun->status === AiRunStatus::Succeeded) {
                if ((int) $actualRun->model_release_id !== (int) $release->id
                    || (int) $actualRun->prompt_version_id !== (int) $promptVersion->id) {
                    $failureCode = 'pinned_release_mismatch';
                    $failureExplanation = 'Фактическая конфигурация AI не совпала с закреплённой версией проверки.';
                } else {
                    $assertionResults = $this->assertionRegistry->evaluate(
                        definitions: $execution['assertions'],
                        expectedSchema: $case->expected_output_schema,
                        outputText: (string) $result->outputText,
                        outputPayload: $result->outputPayload,
                        ragReferences: $references,
                    );
                    $failedAssertion = collect($assertionResults)->first(static fn ($assertion): bool => ! $assertion->passed);
                    if ($failedAssertion === null) {
                        $status = AiEvaluationCaseStatus::Passed;
                        $failureCategory = null;
                        $failureCode = null;
                        $failureExplanation = 'Все проверки выполнены.';
                    } else {
                        $failureCategory = $failedAssertion->category->value;
                        $failureCode = $failedAssertion->failureCode;
                        $failureExplanation = $failedAssertion->explanation;
                        $status = match ($failedAssertion->category) {
                            AiEvaluationCheckCategory::Schema => AiEvaluationCaseStatus::SchemaFailed,
                            AiEvaluationCheckCategory::Rag => AiEvaluationCaseStatus::RagFailed,
                            AiEvaluationCheckCategory::Judge => AiEvaluationCaseStatus::JudgeFailed,
                            default => AiEvaluationCaseStatus::AssertionFailed,
                        };
                    }
                }
            } elseif (($actualRun instanceof AiRun && $actualRun->error_category === AiErrorCategory::OutputSchemaValidationFailed)
                || $result->errorCategory === AiErrorCategory::OutputSchemaValidationFailed
                || $result->status === AiRunStatus::InvalidOutput) {
                $status = AiEvaluationCaseStatus::SchemaFailed;
                $failureCategory = AiEvaluationCheckCategory::Schema->value;
                $failureCode = 'schema_invalid';
                $failureExplanation = 'Ответ AI не соответствует ожидаемому формату.';
            } elseif ($this->hasRagAssertion($execution['assertions'])
                && (($actualRun instanceof AiRun && $actualRun->error_category === AiErrorCategory::ToolExecutionFailed)
                    || $result->errorCategory === AiErrorCategory::ToolExecutionFailed)) {
                $status = AiEvaluationCaseStatus::RagFailed;
                $failureCategory = AiEvaluationCheckCategory::Rag->value;
                $failureCode = 'rag_execution_failed';
                $failureExplanation = 'Не удалось проверить ответ по разрешённым источникам.';
            }

            $providerCost = $this->providerCostData($caseAttempts);
            $executionStatus = $actualRun instanceof AiRun ? $actualRun->status->value : $result->status->value;
            $executionErrorCategory = $actualRun instanceof AiRun && $actualRun->error_category instanceof AiErrorCategory
                ? $actualRun->error_category->value
                : ($result->errorCategory instanceof AiErrorCategory ? $result->errorCategory->value : null);
            $executionLatency = $actualRun instanceof AiRun ? (int) $actualRun->latency_ms : $result->latencyMs;
            $executionTokenUsage = $actualRun instanceof AiRun
                ? $actualRun->getTokenUsage()->toArray()
                : $result->tokenUsage->toArray();
            $executionCost = $actualRun instanceof AiRun ? $actualRun->settled_estimated_cost_minor_units : null;
            $executionCurrency = $actualRun instanceof AiRun && $executionCost !== null
                ? $this->metricsAggregator->estimatedCurrency($actualRun, $caseAttempts)
                : null;
            $executionData = [
                'status' => $executionStatus,
                'provider' => $actualRun?->actual_provider,
                'model' => $actualRun?->actual_model,
                'latency_ms' => $executionLatency,
                'attempt_count' => $caseAttempts->count(),
                'error_category' => $executionErrorCategory,
                'token_usage' => $executionTokenUsage,
                'estimated_cost_minor_units' => $executionCost,
                'provider_cost_minor_units' => $providerCost['minor_units'],
                'provider_cost_by_currency' => $providerCost['by_currency'],
                'provider_cost_unknown_count' => $providerCost['unknown_count'],
                'provider_cost_currency_unknown_count' => $providerCost['currency_unknown_count'],
                'cost_currency' => $this->currency($executionCurrency),
            ];

            $caseResults[] = new AiEvaluationCaseResult(
                caseId: (int) $case->getKey(),
                caseName: $case->name,
                aiRunId: (int) $result->runId,
                status: $status,
                passed: $status->isPassed(),
                failureCategory: $failureCategory,
                failureCode: $failureCode,
                failureExplanation: $failureExplanation,
                assertions: array_map(static fn ($assertion): array => $assertion->toArray(), $assertionResults),
                execution: $executionData,
                rag: [
                    'checks_present' => $this->hasRagAssertion($execution['assertions']),
                    'reference_count' => count($references),
                    'references' => $references,
                ],
                modelReleaseId: $actualRun?->model_release_id,
                actualProvider: $actualRun?->actual_provider,
                actualModel: $actualRun?->actual_model,
            );
        }

        $totalCases = count($caseResults);
        $passedCases = count(array_filter($caseResults, static fn (AiEvaluationCaseResult $result): bool => $result->passed));
        $failedCases = $totalCases - $passedCases;
        $metrics = $this->metricsAggregator->aggregate($organization->getKey(), $actualRuns, $caseResults);
        $columns = $this->metricsAggregator->columns($metrics);
        $executedAt = Carbon::now()->toIso8601String();
        $provenanceSnapshot = $this->provenanceSnapshot($suite, $cases, $promptVersion, $release, $assertionsByCase, $executedAt);

        $evalRun = new AiEvalRun([
            'organization_id' => $organization->getKey(),
            'eval_suite_id' => $suite->id,
            'prompt_version_id' => $promptVersion->id,
            'model_release_id' => $release->id,
            'provider' => $actualRuns->first()?->actual_provider,
            'model' => $actualRuns->first()?->actual_model,
            'total_cases' => $totalCases,
            'passed_cases' => $passedCases,
            'failed_cases' => $failedCases,
            'pass_percentage' => $metrics['pass_percentage'],
            ...$columns,
            'results_payload' => [
                'schema_version' => 2,
                'cases' => array_map(static fn (AiEvaluationCaseResult $result): array => $result->toArray(), $caseResults),
                'metrics' => $metrics,
                'executed_at' => $executedAt,
            ],
            'metrics_payload' => $metrics,
            'provenance_snapshot' => $provenanceSnapshot,
            'executed_by_user_id' => $actor->getKey(),
        ]);
        $evalRun->save();

        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: 'ai.evaluation_run.completed',
            targetType: AiEvalRun::class,
            targetId: (string) $evalRun->getKey(),
            metadata: [
                'eval_suite_id' => (string) $suite->getKey(),
                'total_cases' => $totalCases,
                'passed_cases' => $passedCases,
                'failed_cases' => $failedCases,
            ],
        );

        return $evalRun;
    }

    /** @param list<array<string, mixed>> $assertions */
    private function hasRagAssertion(array $assertions): bool
    {
        foreach ($assertions as $assertion) {
            if (in_array($assertion['type'] ?? null, ['required_source', 'forbidden_source'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, AiRunRagReference>  $references
     * @return list<array<string, mixed>>
     */
    private function ragReferenceData(Collection $references): array
    {
        return array_values($references->map(static fn (AiRunRagReference $reference): array => [
            'reference_index' => (int) $reference->reference_index,
            'source_id' => (int) $reference->knowledge_source_id,
            'source_title' => $reference->source?->title,
            'source_reference' => $reference->chunk?->source_reference,
            'revision_id' => (int) $reference->knowledge_revision_id,
            'chunk_id' => (int) $reference->knowledge_chunk_id,
            'similarity_score' => (float) $reference->similarity_score,
            'configuration_key' => $reference->configuration_key,
        ])->values()->all());
    }

    /**
     * @param  Collection<int, AiRunAttempt>  $attempts
     * @return array{minor_units: int|null, by_currency: array<string, int>, unknown_count: int, currency_unknown_count: int}
     */
    private function providerCostData(Collection $attempts): array
    {
        $byCurrency = [];
        $unknownCount = 0;
        $currencyUnknownCount = 0;

        foreach ($attempts as $attempt) {
            if ($attempt->provider_cost_minor_units === null) {
                $unknownCount++;

                continue;
            }

            $snapshot = $attempt->pricing_snapshot;
            $currency = $this->currency($snapshot['currency'] ?? null);
            if ($currency === null) {
                $unknownCount++;
                $currencyUnknownCount++;

                continue;
            }

            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + (int) $attempt->provider_cost_minor_units;
        }

        return [
            'minor_units' => $unknownCount === 0 && count($byCurrency) === 1 ? (int) array_values($byCurrency)[0] : null,
            'by_currency' => $byCurrency,
            'unknown_count' => $unknownCount,
            'currency_unknown_count' => $currencyUnknownCount,
        ];
    }

    private function currency(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtoupper(trim($value));

        return preg_match('/^[A-Z]{3}$/', $value) === 1 ? $value : null;
    }

    /**
     * @param  Collection<int, AiEvalCase>  $cases
     * @param  array<int, list<array<string, mixed>>>  $assertionsByCase
     * @return array<string, mixed>
     */
    private function provenanceSnapshot(
        AiEvalSuite $suite,
        Collection $cases,
        AiPromptVersion $promptVersion,
        AiModelRelease $release,
        array $assertionsByCase,
        string $executedAt,
    ): array {
        return [
            'schema_version' => 2,
            'suite' => [
                'id' => (int) $suite->getKey(),
                'key' => $suite->key,
                'name' => $suite->name,
                'capability' => $suite->capability->value,
            ],
            'cases' => $cases->map(fn (AiEvalCase $case): array => [
                'id' => (int) $case->getKey(),
                'name' => $case->name,
                'assertions' => $assertionsByCase[$case->getKey()] ?? [],
                'expected_output_schema' => $case->expected_output_schema,
                'test_inputs_digest' => $this->snapshotHasher->testInputsDigest((array) $case->test_inputs),
            ])->values()->all(),
            'prompt_version' => [
                'id' => (int) $promptVersion->getKey(),
                'prompt_id' => (int) $promptVersion->prompt_id,
                'version' => (int) $promptVersion->version,
                'status' => $promptVersion->status->value,
            ],
            'model_release' => [
                'id' => (int) $release->getKey(),
                'model_config_id' => (int) $release->model_config_id,
                'release_number' => (int) $release->release_number,
                'provider' => $release->provider_name,
                'model' => $release->model_name,
                'status' => $release->status,
                'capabilities' => $release->capabilities,
            ],
            'capability' => $suite->capability->value,
            'executed_at' => $executedAt,
        ];
    }
}
