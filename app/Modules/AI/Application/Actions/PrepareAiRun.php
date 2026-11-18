<?php

namespace App\Modules\AI\Application\Actions;

use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Application\Data\ContextAssemblyResult;
use App\Modules\AI\Domain\Contracts\AiOutputValidatorInterface;
use App\Modules\AI\Domain\Contracts\AiSafetyBudgetManagerInterface;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\ClinicalSynthesizerWorkflow;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\AI\Domain\Models\AiRunRagReference;
use App\Modules\AI\Domain\Registry\AiCapabilityRegistry;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use App\Modules\AI\Domain\ValueObjects\AiContextPolicy;
use App\Modules\AI\Domain\ValueObjects\AiExecutionPolicySnapshot;
use App\Modules\AI\Domain\ValueObjects\AiTokenUsage;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingExecutionSnapshot;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PrepareAiRun
{
    public function __construct(
        private readonly AiSafetyBudgetManagerInterface $budgetManager,
        private readonly MedicalEncryptorInterface $medicalEncryptor,
        private readonly ResolveAiExecutionCandidates $candidateResolver,
        private readonly AiOutputValidatorInterface $outputValidator,
    ) {}

    /**
     * @return array{run: AiRun, created: bool, worker_lease_token: string}
     */
    public function claim(
        int $organizationId,
        AiRunRequest $request,
        AiPromptVersion $promptVersion,
        AiContextPolicy $contextPolicy,
        CarbonInterface $executionDeadlineAt,
        int $maxToolCalls,
        ?AiExecutionMode $executionMode = null,
        ?int $initiatedByUserId = null,
    ): array {
        $this->validatePromptContract($request, $promptVersion);

        $workerLeaseToken = (string) Str::uuid();
        $effectiveExecutionMode = $executionMode ?? $request->executionMode;
        $candidateSnapshot = [];
        $executionPolicySnapshot = [];
        $safetyControls = null;
        if ($effectiveExecutionMode === AiExecutionMode::Async) {
            $safetyControls = AiOrganizationSafetyControl::query()
                ->where('organization_id', $organizationId)
                ->first();
            $executionPolicySnapshot = $this->executionPolicySnapshot(
                request: $request,
                promptVersion: $promptVersion,
                contextPolicy: $contextPolicy,
                safetyControls: $safetyControls,
            )->toArray();
            $maxToolCalls = $executionPolicySnapshot['max_tool_calls'];
            $candidateSnapshot = $this->candidateResolver->snapshot(
                organizationId: $organizationId,
                request: $request,
                safetyControls: $safetyControls,
            );
        }
        $retrievalReservation = $this->retrievalReservation($request, $contextPolicy, $maxToolCalls);
        $primaryCandidate = $candidateSnapshot[0] ?? [];
        $keyVersion = (int) Config::get('medical.key_version', 1);
        $created = false;

        try {
            $run = DB::transaction(function () use (
                &$created,
                $organizationId,
                $request,
                $promptVersion,
                $executionDeadlineAt,
                $workerLeaseToken,
                $retrievalReservation,
                $candidateSnapshot,
                $primaryCandidate,
                $effectiveExecutionMode,
                $initiatedByUserId,
                $executionPolicySnapshot,
            ): AiRun {
                if ($request->idempotencyKey !== null) {
                    $existing = AiRun::query()
                        ->where('organization_id', $organizationId)
                        ->where('idempotency_key', $request->idempotencyKey)
                        ->lockForUpdate()
                        ->first();
                    if ($existing !== null) {
                        return $existing;
                    }
                }

                if ($retrievalReservation['maximum_cost_minor_units'] > 0) {
                    $this->budgetManager->reserveBudget(
                        $organizationId,
                        $retrievalReservation['maximum_cost_minor_units'],
                    );
                }

                $run = new AiRun([
                    'organization_id' => $organizationId,
                    'capability' => $request->capability,
                    'workflow_key' => $request->workflowKey,
                    'origin' => $request->origin,
                    'initiated_by_user_id' => $request->actor?->getKey() ?? $initiatedByUserId ?? $request->initiatedByUserId,
                    'client_id' => $request->clientId,
                    'status' => AiRunStatus::Preparing,
                    'execution_mode' => $effectiveExecutionMode,
                    'prompt_id' => $promptVersion->prompt_id,
                    'prompt_version_id' => $promptVersion->id,
                    'model_config_id' => $primaryCandidate['model_config_id'] ?? null,
                    'model_release_id' => $request->modelReleaseId ?? ($primaryCandidate['model_release_id'] ?? null),
                    'requested_provider' => $primaryCandidate['provider'] ?? null,
                    'requested_model' => $primaryCandidate['model'] ?? null,
                    'input_references' => array_map(static fn ($reference): array => $reference->toArray(), $request->inputReferences),
                    'execution_candidate_snapshot' => $candidateSnapshot,
                    'execution_policy_snapshot' => $executionPolicySnapshot,
                    'context_provenance' => [
                        'retrieval_embedding' => [
                            'reserved_query_count' => $retrievalReservation['query_count'],
                            'maximum_cost_minor_units' => $retrievalReservation['maximum_cost_minor_units'],
                            'estimated_cost_minor_units' => 0,
                            'configuration_snapshot' => $retrievalReservation['configuration_snapshot'],
                            'pricing_snapshot' => $retrievalReservation['pricing_snapshot'],
                        ],
                    ],
                    'structured_output_schema_version' => $promptVersion->output_schema ? 'v1' : null,
                    'structured_output_valid' => true,
                    'token_usage' => (new AiTokenUsage)->toArray(),
                    'cost_currency' => 'USD',
                    'idempotency_key' => $request->idempotencyKey,
                    'worker_lease_token' => $workerLeaseToken,
                    'worker_lease_expires_at' => $executionDeadlineAt->copy()->addSeconds(AiRuntimeLimits::PLATFORM_LEASE_GRACE_SECONDS),
                    'execution_deadline_at' => $executionDeadlineAt,
                    'retrieval_embedding_reserved_cost_minor_units' => $retrievalReservation['maximum_cost_minor_units'],
                    'retrieval_embedding_usage_date' => Carbon::now()->toDateString(),
                    'retrieval_embedding_budget_status' => $retrievalReservation['maximum_cost_minor_units'] > 0 ? 'reserved' : 'none',
                    'retrieval_embedding_pricing_snapshot' => $retrievalReservation['pricing_snapshot'],
                ]);
                $run->save();
                $created = true;

                return $run;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if ($request->idempotencyKey === null) {
                throw $exception;
            }

            $run = AiRun::query()
                ->where('organization_id', $organizationId)
                ->where('idempotency_key', $request->idempotencyKey)
                ->first()
                ?? throw $exception;
        }

        return [
            'run' => $run,
            'created' => $created,
            'worker_lease_token' => $workerLeaseToken,
        ];
    }

    private function executionPolicySnapshot(
        AiRunRequest $request,
        AiPromptVersion $promptVersion,
        AiContextPolicy $contextPolicy,
        ?AiOrganizationSafetyControl $safetyControls,
    ): AiExecutionPolicySnapshot {
        $capability = AiCapabilityRegistry::get($request->capability);
        $allowedTools = AiExecutionPolicySnapshot::effectiveAllowedTools(
            capabilityAllowedTools: $capability->allowedTools,
            promptAllowedTools: $promptVersion->allowed_tools,
            disabledTools: $safetyControls !== null ? $safetyControls->disabled_tools : [],
            ragAllowed: $contextPolicy->allows('rag'),
        );

        return new AiExecutionPolicySnapshot(
            maxFailoverAttempts: AiRuntimeLimits::effectiveMaxFailoverAttempts($safetyControls?->max_failover_attempts),
            maxOutputTokens: AiRuntimeLimits::effectiveMaxOutputTokens(
                capability: $capability,
                requestedMaxTokens: $promptVersion->getParameterConfig()->maxTokens,
                organizationMaxTokens: $safetyControls?->max_tokens_per_run,
            ),
            maxToolCalls: $allowedTools === []
                ? 0
                : AiRuntimeLimits::effectiveMaxToolCalls($capability, $safetyControls?->max_tool_calls_per_run),
            attemptTimeoutSeconds: AiRuntimeLimits::effectiveTimeout(
                requestedTimeout: $promptVersion->getParameterConfig()->timeoutSeconds,
                capabilityMaxTimeout: $capability->maxTimeoutSeconds,
                organizationTimeout: $safetyControls?->default_timeout_seconds,
            ),
            allowedTools: $allowedTools,
        );
    }

    public function complete(
        AiRun $run,
        ContextAssemblyResult $contextAssembly,
        string $renderedSystemPrompt,
        string $renderedUserPrompt,
        string $renderedPromptDigest,
        int $keyVersion,
    ): bool {
        return (bool) DB::transaction(function () use (
            $run,
            $contextAssembly,
            $renderedSystemPrompt,
            $renderedUserPrompt,
            $renderedPromptDigest,
            $keyVersion,
        ): bool {
            $lockedRun = AiRun::query()
                ->where('organization_id', $run->organization_id)
                ->whereKey($run->id)
                ->lockForUpdate()
                ->first();

            if ($lockedRun === null || $lockedRun->status !== AiRunStatus::Preparing) {
                return false;
            }

            if ($lockedRun->execution_deadline_at === null || ! AiRuntimeLimits::deadlineIsActive($lockedRun->execution_deadline_at)) {
                $this->budgetManager->chargeRetrievalEmbeddingConservatively($lockedRun);
                $lockedRun->update([
                    'status' => AiRunStatus::TimedOut,
                    'finished_at' => Carbon::now(),
                    'error_message_sanitized' => 'Whole-run execution deadline expired during preparation.',
                ]);

                return false;
            }

            $payload = new AiRunPayload([
                'organization_id' => $lockedRun->organization_id,
                'ai_run_id' => $lockedRun->id,
                'encryption_key_version' => $keyVersion,
                'encrypted_system_prompt' => $this->medicalEncryptor->encryptField($lockedRun->organization_id, $renderedSystemPrompt, $keyVersion),
                'encrypted_user_prompt' => $this->medicalEncryptor->encryptField($lockedRun->organization_id, $renderedUserPrompt, $keyVersion),
            ]);
            $payload->save();

            foreach ($contextAssembly->ragChunks as $index => $ragChunk) {
                AiRunRagReference::create([
                    'organization_id' => $lockedRun->organization_id,
                    'ai_run_id' => $lockedRun->id,
                    'reference_index' => $index + 1,
                    'knowledge_source_id' => $ragChunk->sourceId,
                    'knowledge_revision_id' => $ragChunk->revisionId,
                    'knowledge_chunk_id' => $ragChunk->chunkId,
                    'chunk_index' => $ragChunk->chunkIndex,
                    'similarity_score' => $ragChunk->similarity,
                    'configuration_key' => $ragChunk->embeddingConfigurationKey,
                    'retrieval_type' => 'initial',
                ]);
            }

            $preparationProvenance = is_array($lockedRun->context_provenance ?? null)
                ? $lockedRun->context_provenance
                : [];
            $assembledProvenance = $contextAssembly->provenanceSummary;
            $reservationEmbedding = is_array($preparationProvenance['retrieval_embedding'] ?? null)
                ? $preparationProvenance['retrieval_embedding']
                : [];
            $assembledEmbedding = is_array($assembledProvenance['retrieval_embedding'] ?? null)
                ? $assembledProvenance['retrieval_embedding']
                : [];
            $assembledEmbedding['reserved_query_count'] = (int) ($reservationEmbedding['reserved_query_count'] ?? 0);
            $assembledEmbedding['maximum_cost_minor_units'] = (int) ($reservationEmbedding['maximum_cost_minor_units'] ?? 0);
            $assembledEmbedding['configuration_snapshot'] = $reservationEmbedding['configuration_snapshot'] ?? [];
            $assembledEmbedding['pricing_snapshot'] = $reservationEmbedding['pricing_snapshot'] ?? [];
            $assembledProvenance['retrieval_embedding'] = $assembledEmbedding;
            if ($contextAssembly->attachmentProvenance !== []) {
                $assembledProvenance['attachments'] = $contextAssembly->attachmentProvenance;
            }
            $sessionPreparationMode = $contextAssembly->variables['session_preparation_mode'] ?? null;
            if (in_array($sessionPreparationMode, ['client_summary', 'factual_session_preparation'], true)) {
                $assembledProvenance['session_preparation_mode'] = $sessionPreparationMode;
            }

            $status = $lockedRun->execution_mode === AiExecutionMode::Async
                ? AiRunStatus::Queued
                : AiRunStatus::Running;
            $lockedRun->update([
                'status' => $status,
                'rendered_prompt_digest' => $renderedPromptDigest,
                'context_provenance' => $assembledProvenance,
                'worker_lease_token' => $lockedRun->worker_lease_token,
                'worker_lease_expires_at' => $lockedRun->worker_lease_expires_at,
                'queued_at' => $status === AiRunStatus::Queued ? Carbon::now() : null,
                'started_at' => $status === AiRunStatus::Running ? Carbon::now() : null,
            ]);

            return true;
        });
    }

    public function fail(AiRun $run, string $message, bool $timedOut = false): void
    {
        DB::transaction(function () use ($run, $message, $timedOut): void {
            $lockedRun = AiRun::query()
                ->where('organization_id', $run->organization_id)
                ->whereKey($run->id)
                ->lockForUpdate()
                ->first();

            if ($lockedRun === null || $lockedRun->status !== AiRunStatus::Preparing) {
                return;
            }

            $this->budgetManager->chargeRetrievalEmbeddingConservatively($lockedRun);
            $lockedRun->update([
                'status' => $timedOut ? AiRunStatus::TimedOut : AiRunStatus::Failed,
                'finished_at' => Carbon::now(),
                'worker_lease_expires_at' => Carbon::now(),
                'error_message_sanitized' => $message,
            ]);
        });
    }

    /** @return array{query_count: int, maximum_cost_minor_units: int, configuration_snapshot: array<string, mixed>, pricing_snapshot: array<string, mixed>} */
    private function retrievalReservation(
        AiRunRequest $request,
        AiContextPolicy $contextPolicy,
        int $maxToolCalls,
    ): array {
        $initialQuery = AiRuntimeLimits::ragQuery($request->inputVariables);
        $initialQueryCount = $contextPolicy->includeRag && $initialQuery !== '' ? 1 : 0;
        $toolQueryCount = min(AiRuntimeLimits::PLATFORM_MAX_TOOL_CALLS, max(0, $maxToolCalls));
        $queryCount = $initialQueryCount + $toolQueryCount;
        if ($queryCount === 0) {
            return [
                'query_count' => 0,
                'maximum_cost_minor_units' => 0,
                'configuration_snapshot' => [],
                'pricing_snapshot' => [],
            ];
        }

        try {
            $snapshot = EmbeddingExecutionSnapshot::active();
        } catch (InvalidArgumentException $exception) {
            if (! $contextPolicy->allowRagDegradation || $contextPolicy->requireGroundedRag) {
                throw $exception;
            }

            return [
                'query_count' => 0,
                'maximum_cost_minor_units' => 0,
                'configuration_snapshot' => [],
                'pricing_snapshot' => [],
            ];
        }

        return [
            'query_count' => $queryCount,
            'maximum_cost_minor_units' => $queryCount * $snapshot->pricing->maximumQueryCost(),
            'configuration_snapshot' => $snapshot->configuration->toArray(),
            'pricing_snapshot' => $snapshot->pricing->toArray(),
        ];
    }

    private function validatePromptContract(AiRunRequest $request, AiPromptVersion $promptVersion): void
    {
        $variablesSchema = (array) ($promptVersion->variables_schema ?? []);
        if ($variablesSchema !== [] && ! $this->outputValidator->validate($request->inputVariables, $variablesSchema)) {
            throw new InvalidArgumentException('AI prompt variables do not satisfy the active prompt version schema.');
        }

        $isSessionPreparation = $request->capability === AiCapability::ClinicalSynthesizer
            && $request->workflowKey === ClinicalSynthesizerWorkflow::SessionDraft->value;
        if (! $isSessionPreparation) {
            return;
        }

        if (($request->inputVariables['session_preparation_mode'] ?? null) !== 'factual_session_preparation') {
            throw new InvalidArgumentException('Clinical session preparation requires factual_session_preparation mode.');
        }

        $variableProperties = is_array($variablesSchema['properties'] ?? null)
            ? $variablesSchema['properties']
            : [];
        $modeSchema = is_array($variableProperties['session_preparation_mode'] ?? null)
            ? $variableProperties['session_preparation_mode']
            : [];
        $modeEnum = is_array($modeSchema['enum'] ?? null) ? $modeSchema['enum'] : [];
        $requiredVariables = is_array($variablesSchema['required'] ?? null)
            ? array_map('strval', $variablesSchema['required'])
            : [];
        $outputSchema = is_array($promptVersion->output_schema) ? $promptVersion->output_schema : [];
        $outputProperties = is_array($outputSchema['properties'] ?? null)
            ? $outputSchema['properties']
            : [];
        $requiredOutputFields = is_array($outputSchema['required'] ?? null)
            ? array_map('strval', $outputSchema['required'])
            : [];
        $promptText = mb_strtolower($promptVersion->system_prompt."\n".$promptVersion->user_prompt_template);

        $hasFactualContract = in_array('factual_session_preparation', $modeEnum, true)
            && in_array('session_preparation_mode', $requiredVariables, true)
            && str_contains($promptText, 'factual_session_preparation')
            && str_contains($promptText, 'не создавай диагноз')
            && str_contains($promptText, 'не интерпретируй факты')
            && str_contains($promptText, '{{session_preparation_mode}}')
            && array_key_exists('source_facts', $outputProperties)
            && array_key_exists('hypotheses', $outputProperties)
            && array_key_exists('critical_limitations_risks', $outputProperties)
            && array_key_exists('recommended_first_session_focus', $outputProperties)
            && array_key_exists('missing_information', $outputProperties)
            && count(array_diff([
                'client_summary',
                'main_request',
                'source_facts',
                'hypotheses',
                'critical_limitations_risks',
                'blind_spots_questions',
                'recommended_first_session_focus',
                'missing_information',
            ], $requiredOutputFields)) === 0;

        if (! $hasFactualContract) {
            throw new InvalidArgumentException(
                'Active clinical synthesizer prompt does not provide the factual session preparation contract.',
            );
        }
    }
}
