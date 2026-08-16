<?php

namespace App\Modules\AI\Infrastructure\Engine;

use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Application\Data\AiRunResult;
use App\Modules\AI\Domain\Contracts\AiContextAssemblerInterface;
use App\Modules\AI\Domain\Contracts\AiOutputValidatorInterface;
use App\Modules\AI\Domain\Contracts\AiPricingCalculatorInterface;
use App\Modules\AI\Domain\Contracts\AiPromptRendererInterface;
use App\Modules\AI\Domain\Contracts\AiSafetyBudgetManagerInterface;
use App\Modules\AI\Domain\Contracts\AiToolRegistryInterface;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiErrorCategory;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\BudgetReservationStatus;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Exceptions\AiBudgetExceededException;
use App\Modules\AI\Domain\Exceptions\AiKillSwitchException;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunAttempt;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\AI\Domain\Models\AiRunRagReference;
use App\Modules\AI\Domain\Registry\AiCapabilityRegistry;
use App\Modules\AI\Domain\ValueObjects\AiContextPolicy;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Domain\ValueObjects\AiTokenUsage;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class LaravelAiWorkflowEngine implements AiWorkflowEngine
{
    public function __construct(
        private readonly AiContextAssemblerInterface $contextAssembler,
        private readonly AiPromptRendererInterface $promptRenderer,
        private readonly AiOutputValidatorInterface $outputValidator,
        private readonly AiPricingCalculatorInterface $pricingCalculator,
        private readonly AiSafetyBudgetManagerInterface $budgetManager,
        private readonly AiToolRegistryInterface $toolRegistry,
        private readonly MedicalEncryptorInterface $medicalEncryptor,
    ) {}

    public function run(int $organizationId, AiRunRequest $request): AiRunResult
    {
        $capabilityDef = AiCapabilityRegistry::get($request->capability);

        $safetyControls = AiOrganizationSafetyControl::query()
            ->where('organization_id', $organizationId)
            ->first();

        if ($safetyControls !== null && ! $safetyControls->is_ai_globally_enabled) {
            throw new AiKillSwitchException('AI functionality is disabled by organization safety control.');
        }

        if ($safetyControls !== null && ! $safetyControls->isCapabilityEnabled($request->capability->value)) {
            throw new AiKillSwitchException("Capability {$request->capability->value} is disabled for organization.");
        }

        foreach ($request->inputReferences as $ref) {
            if (! in_array($ref->type, $capabilityDef->allowedInputReferenceTypes, true)) {
                throw new InvalidArgumentException("Input reference type '{$ref->type}' is not allowed for capability '{$request->capability->value}'.");
            }
        }

        // Idempotency: if key is provided, check if run already exists
        if ($request->idempotencyKey !== null) {
            $existingRun = AiRun::query()
                ->where('organization_id', $organizationId)
                ->where('idempotency_key', $request->idempotencyKey)
                ->first();

            if ($existingRun !== null) {
                if ($existingRun->status->isTerminal()) {
                    $payload = AiRunPayload::query()
                        ->where('organization_id', $organizationId)
                        ->where('ai_run_id', $existingRun->id)
                        ->first();

                    $outputPayload = null;
                    if ($payload?->encrypted_output_payload !== null) {
                        $decrypted = $this->medicalEncryptor->decryptField(
                            $organizationId,
                            $payload->encrypted_output_payload,
                            $payload->encryption_key_version
                        );
                        if ($decrypted !== null) {
                            $decoded = json_decode($decrypted, true);
                            if (is_array($decoded)) {
                                $outputPayload = $decoded;
                            }
                        }
                    }

                    return new AiRunResult(
                        runId: $existingRun->id,
                        status: $existingRun->status,
                        outputPayload: $outputPayload,
                        tokenUsage: $existingRun->getTokenUsage(),
                        settledEstimatedCostMinorUnits: $existingRun->settled_estimated_cost_minor_units ?? 0,
                        latencyMs: $existingRun->latency_ms,
                        errorCategory: $existingRun->error_category,
                        errorMessageSanitized: $existingRun->error_message_sanitized,
                    );
                }

                // If existing run is in-progress and lease is valid, return in-progress without calling provider
                if ($existingRun->worker_lease_expires_at !== null && ! $existingRun->worker_lease_expires_at->isPast()) {
                    return new AiRunResult(
                        runId: $existingRun->id,
                        status: $existingRun->status,
                        tokenUsage: $existingRun->getTokenUsage(),
                    );
                }
            }
        }

        $promptVersion = null;
        if ($request->promptVersionId !== null) {
            $promptVersion = AiPromptVersion::query()
                ->where('organization_id', $organizationId)
                ->where('id', $request->promptVersionId)
                ->first();
        } else {
            $prompt = AiPrompt::query()
                ->where('organization_id', $organizationId)
                ->where('capability', $request->capability)
                ->first();

            if ($prompt !== null && $prompt->active_version_id !== null) {
                $promptVersion = AiPromptVersion::query()
                    ->where('organization_id', $organizationId)
                    ->where('id', $prompt->active_version_id)
                    ->first();
            }
        }

        $contextPolicy = $promptVersion !== null
            ? $promptVersion->getContextPolicy()
            : new AiContextPolicy(includeRag: $capabilityDef->supportsRag);

        $contextAssembly = $this->contextAssembler->assemble(
            organizationId: $organizationId,
            policy: $contextPolicy,
            inputVariables: $request->inputVariables,
            inputReferences: $request->inputReferences,
        );

        $systemPromptTemplate = $promptVersion !== null
            ? $promptVersion->system_prompt
            : 'You are a clinical wellness AI assistant. Provide concise, factual information.';
        $userPromptTemplate = $promptVersion !== null
            ? $promptVersion->user_prompt_template
            : '{{query}}';

        $renderedSystemPrompt = $this->promptRenderer->render($systemPromptTemplate, $contextAssembly->variables);
        $renderedUserPrompt = $this->promptRenderer->render($userPromptTemplate, $contextAssembly->variables);
        $renderedPromptDigest = hash('sha256', $renderedSystemPrompt."\n---\n".$renderedUserPrompt);

        $keyVersion = (int) Config::get('medical.key_version', 1);
        $leaseToken = (string) Str::uuid();
        $timeoutSeconds = $request->timeoutSeconds ?? $capabilityDef->defaultTimeoutSeconds;
        $leaseExpiresAt = Carbon::now()->addSeconds($timeoutSeconds + max(60, $timeoutSeconds));

        /** @var AiRun|null $run */
        $run = null;

        try {
            DB::transaction(function () use (
                &$run,
                $organizationId,
                $request,
                $promptVersion,
                $renderedPromptDigest,
                $contextAssembly,
                $leaseToken,
                $leaseExpiresAt,
                $keyVersion,
                $renderedSystemPrompt,
                $renderedUserPrompt,
            ) {
                $run = new AiRun([
                    'organization_id' => $organizationId,
                    'capability' => $request->capability,
                    'workflow_key' => $request->workflowKey,
                    'origin' => $request->origin,
                    'initiated_by_user_id' => $request->initiatedByUserId,
                    'client_id' => $request->clientId,
                    'status' => AiRunStatus::Running,
                    'execution_mode' => $request->executionMode,
                    'prompt_id' => $promptVersion?->prompt_id,
                    'prompt_version_id' => $promptVersion?->id,
                    'input_references' => array_map(fn ($r) => $r->toArray(), $request->inputReferences),
                    'rendered_prompt_digest' => $renderedPromptDigest,
                    'context_provenance' => $contextAssembly->provenanceSummary,
                    'structured_output_schema_version' => $promptVersion?->output_schema ? 'v1' : null,
                    'structured_output_valid' => true,
                    'token_usage' => (new AiTokenUsage)->toArray(),
                    'cost_currency' => 'USD',
                    'idempotency_key' => $request->idempotencyKey,
                    'worker_lease_token' => $leaseToken,
                    'worker_lease_expires_at' => $leaseExpiresAt,
                    'started_at' => Carbon::now(),
                ]);
                $run->save();

                $payload = new AiRunPayload([
                    'organization_id' => $organizationId,
                    'ai_run_id' => $run->id,
                    'encryption_key_version' => $keyVersion,
                    'encrypted_system_prompt' => $this->medicalEncryptor->encryptField($organizationId, $renderedSystemPrompt, $keyVersion),
                    'encrypted_user_prompt' => $this->medicalEncryptor->encryptField($organizationId, $renderedUserPrompt, $keyVersion),
                ]);
                $payload->save();

                foreach ($contextAssembly->ragChunks as $index => $ragChunk) {
                    AiRunRagReference::create([
                        'organization_id' => $organizationId,
                        'ai_run_id' => $run->id,
                        'reference_index' => $index + 1,
                        'knowledge_source_id' => $ragChunk->sourceId,
                        'knowledge_revision_id' => $ragChunk->revisionId,
                        'knowledge_chunk_id' => $ragChunk->chunkId,
                        'chunk_index' => $ragChunk->chunkIndex,
                        'similarity_score' => $ragChunk->similarity,
                        'configuration_key' => 'default',
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($request->idempotencyKey !== null) {
                $existingRun = AiRun::query()
                    ->where('organization_id', $organizationId)
                    ->where('idempotency_key', $request->idempotencyKey)
                    ->first();

                if ($existingRun !== null) {
                    return new AiRunResult(
                        runId: $existingRun->id,
                        status: $existingRun->status,
                        tokenUsage: $existingRun->getTokenUsage(),
                    );
                }
            }
            throw $e;
        }

        if ($run === null) {
            return new AiRunResult(
                runId: 0,
                status: AiRunStatus::Failed,
                errorCategory: AiErrorCategory::InternalError,
                errorMessageSanitized: 'Failed to initialize AI run.',
            );
        }

        return $this->executeRun($organizationId, $run->id, $leaseToken);
    }

    public function executeRun(int $organizationId, int $runId, string $workerLeaseToken): AiRunResult
    {
        $run = AiRun::query()
            ->where('organization_id', $organizationId)
            ->where('id', $runId)
            ->first();

        if ($run === null) {
            return new AiRunResult(
                runId: $runId,
                status: AiRunStatus::Failed,
                errorCategory: AiErrorCategory::InternalError,
                errorMessageSanitized: 'AI run not found.',
            );
        }

        if ($run->status->isTerminal()) {
            return new AiRunResult(
                runId: $run->id,
                status: $run->status,
                tokenUsage: $run->getTokenUsage(),
                settledEstimatedCostMinorUnits: $run->settled_estimated_cost_minor_units ?? 0,
                latencyMs: $run->latency_ms,
                errorCategory: $run->error_category,
                errorMessageSanitized: $run->error_message_sanitized,
            );
        }

        $capabilityDef = AiCapabilityRegistry::get($run->capability);

        $safetyControls = AiOrganizationSafetyControl::query()
            ->where('organization_id', $organizationId)
            ->first();

        if ($safetyControls !== null && ! $safetyControls->is_ai_globally_enabled) {
            $run->update([
                'status' => AiRunStatus::Failed,
                'error_category' => AiErrorCategory::InternalError,
                'error_message_sanitized' => 'AI functionality is disabled by organization safety control.',
                'finished_at' => Carbon::now(),
            ]);

            return new AiRunResult(
                runId: $run->id,
                status: AiRunStatus::Failed,
                errorCategory: AiErrorCategory::InternalError,
                errorMessageSanitized: 'AI functionality is disabled by organization safety control.',
            );
        }

        if ($safetyControls !== null && ! $safetyControls->isCapabilityEnabled($run->capability->value)) {
            $run->update([
                'status' => AiRunStatus::Failed,
                'error_category' => AiErrorCategory::InternalError,
                'error_message_sanitized' => "Capability {$run->capability->value} is disabled for organization.",
                'finished_at' => Carbon::now(),
            ]);

            return new AiRunResult(
                runId: $run->id,
                status: AiRunStatus::Failed,
                errorCategory: AiErrorCategory::InternalError,
                errorMessageSanitized: "Capability {$run->capability->value} is disabled for organization.",
            );
        }

        $payload = AiRunPayload::query()
            ->where('organization_id', $organizationId)
            ->where('ai_run_id', $run->id)
            ->first();

        $keyVersion = $payload !== null ? $payload->encryption_key_version : (int) Config::get('medical.key_version', 1);

        $decryptedSystemPrompt = $payload?->encrypted_system_prompt !== null
            ? $this->medicalEncryptor->decryptField($organizationId, $payload->encrypted_system_prompt, $keyVersion)
            : 'You are a clinical wellness assistant.';
        $decryptedUserPrompt = $payload?->encrypted_user_prompt !== null
            ? $this->medicalEncryptor->decryptField($organizationId, $payload->encrypted_user_prompt, $keyVersion)
            : '';

        $promptVersion = $run->prompt_version_id !== null
            ? AiPromptVersion::query()->where('organization_id', $organizationId)->where('id', $run->prompt_version_id)->first()
            : null;

        $modelConfigs = AiModelConfiguration::query()
            ->where('organization_id', $organizationId)
            ->where('is_enabled', true)
            ->orderBy('failover_priority', 'asc')
            ->get()
            ->filter(fn (AiModelConfiguration $m) => $m->supportsCapability($run->capability->value));

        $candidates = [];
        foreach ($modelConfigs as $config) {
            /** @var AiProviderConfiguration|null $providerConfig */
            $providerConfig = $config->providerConfiguration;
            if ($providerConfig === null || ! $providerConfig->is_enabled) {
                continue;
            }

            if ($safetyControls !== null && ! $safetyControls->isProviderEnabled($providerConfig->provider_name)) {
                continue;
            }

            $credential = $providerConfig->credential;
            $release = $config->activeRelease;

            $candidates[] = [
                'provider' => $providerConfig->provider_name,
                'model' => $config->model_name,
                'release' => $release,
                'credential_id' => $credential?->id,
                'credential_revision' => $credential?->revision_id,
            ];
        }

        if (empty($candidates)) {
            // Default fallback candidate if testing or standard setup
            $defaultRelease = new AiModelRelease([
                'pricing_snapshot' => (new AiPricingSnapshot(currency: 'USD', inputCostPerMillionMinorUnits: 15, outputCostPerMillionMinorUnits: 60))->toArray(),
            ]);
            $candidates = [
                [
                    'provider' => 'openai',
                    'model' => 'gpt-4o-mini',
                    'release' => $defaultRelease,
                    'credential_id' => null,
                    'credential_revision' => null,
                ],
            ];
        }

        $maxAttempts = $safetyControls !== null ? $safetyControls->max_failover_attempts : 3;
        $attemptNumber = (int) AiRunAttempt::query()
            ->where('organization_id', $organizationId)
            ->where('ai_run_id', $runId)
            ->max('attempt_number');
        $today = Carbon::now()->toDateString();
        $iterationCount = 0;

        foreach ($candidates as $candidate) {
            $iterationCount++;
            if ($iterationCount > $maxAttempts) {
                break;
            }
            $attemptNumber++;

            /** @var AiModelRelease|null $release */
            $release = $candidate['release'];
            $pricing = $release !== null ? $release->getPricingSnapshot() : new AiPricingSnapshot;

            $maxTokens = $promptVersion?->getParameterConfig()->maxTokens ?? $capabilityDef->defaultMaxTokens;
            $worstCaseEstimatedCost = $pricing->calculateCostMinorUnits($maxTokens, $maxTokens);

            /** @var AiRunAttempt|null $attempt */
            $attempt = null;

            // FENCING CHECK + ATTEMPT CREATION + BUDGET RESERVATION IN SHORT TRANSACTION
            try {
                DB::transaction(function () use (
                    &$attempt,
                    $organizationId,
                    $runId,
                    $workerLeaseToken,
                    $attemptNumber,
                    $candidate,
                    $release,
                    $worstCaseEstimatedCost,
                    $today,
                    $pricing,
                ) {
                    /** @var AiRun|null $lockedRun */
                    $lockedRun = AiRun::query()
                        ->where('organization_id', $organizationId)
                        ->where('id', $runId)
                        ->lockForUpdate()
                        ->first();

                    if ($lockedRun === null || $lockedRun->worker_lease_token !== $workerLeaseToken || $lockedRun->status !== AiRunStatus::Running) {
                        throw new InvalidArgumentException('Stale worker lease lost.');
                    }

                    $this->budgetManager->reserveBudget($organizationId, $worstCaseEstimatedCost);

                    $attempt = new AiRunAttempt([
                        'organization_id' => $organizationId,
                        'ai_run_id' => $runId,
                        'attempt_number' => $attemptNumber,
                        'provider' => $candidate['provider'],
                        'model' => $candidate['model'],
                        'model_release_id' => $release?->id,
                        'credential_id' => $candidate['credential_id'],
                        'credential_revision' => $candidate['credential_revision'],
                        'status' => 'running',
                        'reserved_cost_minor_units' => $worstCaseEstimatedCost,
                        'budget_usage_date' => $today,
                        'budget_reservation_status' => BudgetReservationStatus::Reserved,
                        'pricing_snapshot' => $pricing->toArray(),
                        'token_usage' => (new AiTokenUsage)->toArray(),
                        'started_at' => Carbon::now(),
                    ]);
                    $attempt->save();
                });
            } catch (AiBudgetExceededException $e) {
                $run->update([
                    'status' => AiRunStatus::Failed,
                    'error_category' => AiErrorCategory::BudgetExceeded,
                    'error_message_sanitized' => $e->getMessage(),
                    'finished_at' => Carbon::now(),
                ]);

                return new AiRunResult(
                    runId: $run->id,
                    status: AiRunStatus::Failed,
                    errorCategory: AiErrorCategory::BudgetExceeded,
                    errorMessageSanitized: $e->getMessage(),
                );
            } catch (Throwable $e) {
                if ($e->getMessage() === 'Stale worker lease lost.') {
                    return new AiRunResult(
                        runId: $run->id,
                        status: AiRunStatus::Failed,
                        errorCategory: AiErrorCategory::InternalError,
                        errorMessageSanitized: 'Stale worker lease lost before execution attempt.',
                    );
                }

                continue;
            }

            if ($attempt === null) {
                continue;
            }

            $startTime = microtime(true);
            $outputText = null;

            // EXTERNAL CALL OUTSIDE DB TRANSACTION
            try {
                $tools = $this->toolRegistry->all();

                $agent = new DynamicWorkflowAgent(
                    instructionsText: $decryptedSystemPrompt ?: 'You are a clinical wellness AI assistant.',
                    agentTools: $tools,
                );

                $response = $agent->prompt($decryptedUserPrompt ?: 'Hello');
                $outputText = (string) $response;

                $elapsedMs = (int) round((microtime(true) - $startTime) * 1000);

                $promptTokensEstimated = (int) ceil(mb_strlen(($decryptedSystemPrompt ?: '').($decryptedUserPrompt ?: '')) / 4);
                $completionTokensEstimated = (int) ceil(mb_strlen($outputText) / 4);
                $tokenUsage = new AiTokenUsage(
                    promptTokens: $promptTokensEstimated,
                    completionTokens: $completionTokensEstimated,
                    totalTokens: $promptTokensEstimated + $completionTokensEstimated,
                );

                $settledCost = $this->pricingCalculator->calculateEstimatedCost(
                    pricing: $pricing,
                    promptTokens: $tokenUsage->promptTokens,
                    completionTokens: $tokenUsage->completionTokens,
                );

                $outputPayload = null;
                $outputSchema = $promptVersion !== null ? $promptVersion->output_schema : $capabilityDef->defaultOutputSchema;
                $isValid = true;

                if ($outputSchema !== null) {
                    $isValid = $this->outputValidator->validate($outputText, $outputSchema);
                    if ($isValid) {
                        $outputPayload = json_decode($outputText, true);
                    }
                }

                $encodedPayload = $outputPayload !== null ? json_encode($outputPayload) : null;
                $humanReviewStatus = ($capabilityDef->requiresHumanReview || ($promptVersion !== null && $promptVersion->output_schema !== null))
                    ? HumanReviewStatus::PendingReview
                    : HumanReviewStatus::NotRequired;

                $validationError = ! $isValid ? $this->outputValidator->getValidationError() : null;
                $runStatus = $isValid ? AiRunStatus::Succeeded : AiRunStatus::InvalidOutput;

                $staleLoss = false;

                // FENCING CHECK + ATOMIC SETTLEMENT + DURABLE FINAL STATE WRITE
                DB::transaction(function () use (
                    &$staleLoss,
                    $organizationId,
                    $runId,
                    $workerLeaseToken,
                    $attempt,
                    $settledCost,
                    $elapsedMs,
                    $tokenUsage,
                    $payload,
                    $outputText,
                    $encodedPayload,
                    $keyVersion,
                    $runStatus,
                    $candidate,
                    $release,
                    $humanReviewStatus,
                    $validationError,
                    $isValid,
                ) {
                    /** @var AiRun|null $lockedRun */
                    $lockedRun = AiRun::query()
                        ->where('organization_id', $organizationId)
                        ->where('id', $runId)
                        ->lockForUpdate()
                        ->first();

                    if ($lockedRun === null || $lockedRun->worker_lease_token !== $workerLeaseToken || $lockedRun->status !== AiRunStatus::Running) {
                        $staleLoss = true;
                        $this->budgetManager->chargeAttemptConservatively($attempt);
                        $attempt->update([
                            'status' => 'failed',
                            'error_category' => AiErrorCategory::InternalError,
                            'error_message_sanitized' => 'Stale worker lease lost during external call.',
                        ]);

                        return;
                    }

                    $this->budgetManager->settleAttemptBudget($attempt, $settledCost);

                    $attempt->update([
                        'status' => 'succeeded',
                        'latency_ms' => $elapsedMs,
                        'token_usage' => $tokenUsage->toArray(),
                        'finished_at' => Carbon::now(),
                    ]);

                    if ($payload !== null) {
                        $payload->update([
                            'encrypted_output_text' => $this->medicalEncryptor->encryptField($organizationId, $outputText, $keyVersion),
                            'encrypted_output_payload' => is_string($encodedPayload)
                                ? $this->medicalEncryptor->encryptField($organizationId, $encodedPayload, $keyVersion)
                                : null,
                        ]);
                    }

                    $lockedRun->update([
                        'status' => $runStatus,
                        'actual_provider' => $candidate['provider'],
                        'actual_model' => $candidate['model'],
                        'model_release_id' => $release?->id,
                        'latency_ms' => $elapsedMs,
                        'settled_estimated_cost_minor_units' => $settledCost,
                        'token_usage' => $tokenUsage->toArray(),
                        'human_review_status' => $humanReviewStatus,
                        'structured_output_valid' => $isValid,
                        'error_category' => ! $isValid ? AiErrorCategory::OutputSchemaValidationFailed : null,
                        'error_message_sanitized' => $validationError,
                        'finished_at' => Carbon::now(),
                    ]);
                });

                if ($staleLoss) {
                    return new AiRunResult(
                        runId: $run->id,
                        status: AiRunStatus::Failed,
                        errorCategory: AiErrorCategory::InternalError,
                        errorMessageSanitized: 'Stale worker lease lost during execution.',
                    );
                }

                return new AiRunResult(
                    runId: $run->id,
                    status: $runStatus,
                    outputText: $outputText,
                    outputPayload: $outputPayload,
                    tokenUsage: $tokenUsage,
                    settledEstimatedCostMinorUnits: $settledCost,
                    latencyMs: $elapsedMs,
                    errorCategory: ! $isValid ? AiErrorCategory::OutputSchemaValidationFailed : null,
                    errorMessageSanitized: $validationError,
                );
            } catch (Throwable $e) {
                $elapsedMs = (int) round((microtime(true) - $startTime) * 1000);

                if ($attempt->status === 'running') {
                    $this->budgetManager->releaseAttemptBudget($attempt);
                    $attempt->update([
                        'status' => 'failed',
                        'latency_ms' => $elapsedMs,
                        'error_category' => AiErrorCategory::ProviderUnavailable,
                        'error_message_sanitized' => $e->getMessage(),
                        'finished_at' => Carbon::now(),
                    ]);
                }
            }
        }

        $run->update([
            'status' => AiRunStatus::Failed,
            'error_category' => AiErrorCategory::ProviderUnavailable,
            'error_message_sanitized' => 'All provider attempts failed or timed out.',
            'finished_at' => Carbon::now(),
        ]);

        return new AiRunResult(
            runId: $run->id,
            status: AiRunStatus::Failed,
            errorCategory: AiErrorCategory::ProviderUnavailable,
            errorMessageSanitized: 'All provider attempts failed or timed out.',
        );
    }
}
