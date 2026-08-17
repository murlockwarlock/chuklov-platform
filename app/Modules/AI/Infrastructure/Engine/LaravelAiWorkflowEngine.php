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
use App\Modules\AI\Domain\Exceptions\AiProviderUnavailableException;
use App\Modules\AI\Domain\Exceptions\AiRagRetrievalException;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunAttempt;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\AI\Domain\Models\AiRunRagReference;
use App\Modules\AI\Domain\Registry\AiCapabilityRegistry;
use App\Modules\AI\Domain\Services\AiErrorSanitizer;
use App\Modules\AI\Domain\ValueObjects\AiContextPolicy;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Domain\ValueObjects\AiTokenUsage;
use App\Modules\AI\Infrastructure\Providers\AiProviderFactory;
use App\Modules\AI\Infrastructure\Tools\SearchKnowledgeBaseSdkTool;
use App\Modules\AI\Infrastructure\Tools\SearchKnowledgeBaseTool;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Prompts\AgentPrompt;
use Throwable;

class LaravelAiWorkflowEngine implements AiWorkflowEngine
{
    public const int PLATFORM_MAX_TIMEOUT_SECONDS = 180;

    public function __construct(
        private readonly AiContextAssemblerInterface $contextAssembler,
        private readonly AiPromptRendererInterface $promptRenderer,
        private readonly AiOutputValidatorInterface $outputValidator,
        private readonly AiPricingCalculatorInterface $pricingCalculator,
        private readonly AiSafetyBudgetManagerInterface $budgetManager,
        private readonly AiToolRegistryInterface $toolRegistry,
        private readonly MedicalEncryptorInterface $medicalEncryptor,
        private readonly AiProviderFactory $providerFactory,
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

        try {
            $contextAssembly = $this->contextAssembler->assemble(
                organizationId: $organizationId,
                policy: $contextPolicy,
                inputVariables: $request->inputVariables,
                inputReferences: $request->inputReferences,
            );
        } catch (AiRagRetrievalException $e) {
            throw $e;
        }

        $systemPromptTemplate = $promptVersion !== null ? $promptVersion->system_prompt : 'You are a clinical wellness assistant.';
        $userPromptTemplate = $promptVersion !== null ? $promptVersion->user_prompt_template : 'Process the following input: {{ query }}';

        $renderedSystemPrompt = $this->promptRenderer->render($systemPromptTemplate, $contextAssembly->variables);
        $renderedUserPrompt = $this->promptRenderer->render($userPromptTemplate, $contextAssembly->variables);
        $renderedPromptDigest = hash('sha256', $renderedSystemPrompt."\n---\n".$renderedUserPrompt);

        $configuredTimeout = $promptVersion?->getParameterConfig()->timeoutSeconds ?? $request->timeoutSeconds ?? $capabilityDef->defaultTimeoutSeconds;
        $attemptTimeout = min($configuredTimeout, $capabilityDef->maxTimeoutSeconds, self::PLATFORM_MAX_TIMEOUT_SECONDS);
        $leaseTtl = $attemptTimeout + max(30, (int) round($attemptTimeout * 0.5));

        $leaseToken = (string) Str::uuid();
        $leaseExpiresAt = Carbon::now()->addSeconds($leaseTtl);
        $keyVersion = (int) Config::get('medical.key_version', 1);

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
                        'configuration_key' => $ragChunk->embeddingConfigurationKey,
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

        // 1. FENCED KILL-SWITCH TRANSITION
        if ($safetyControls !== null && ! $safetyControls->is_ai_globally_enabled) {
            $this->fencedTerminalRunTransition(
                $organizationId,
                $runId,
                $workerLeaseToken,
                AiRunStatus::Failed,
                AiErrorCategory::SafetyKillSwitchActive,
                'AI functionality is disabled by organization safety control.'
            );

            return new AiRunResult(
                runId: $run->id,
                status: AiRunStatus::Failed,
                errorCategory: AiErrorCategory::SafetyKillSwitchActive,
                errorMessageSanitized: 'AI functionality is disabled by organization safety control.',
            );
        }

        // 2. FENCED DISABLED-CAPABILITY TRANSITION
        if ($safetyControls !== null && ! $safetyControls->isCapabilityEnabled($run->capability->value)) {
            $this->fencedTerminalRunTransition(
                $organizationId,
                $runId,
                $workerLeaseToken,
                AiRunStatus::Failed,
                AiErrorCategory::SafetyKillSwitchActive,
                "Capability {$run->capability->value} is disabled for organization."
            );

            return new AiRunResult(
                runId: $run->id,
                status: AiRunStatus::Failed,
                errorCategory: AiErrorCategory::SafetyKillSwitchActive,
                errorMessageSanitized: "Capability {$run->capability->value} is disabled for organization.",
            );
        }

        // 3. FENCED RATE LIMIT CHECK (max_runs_per_minute)
        $maxRunsPerMinute = $safetyControls !== null ? $safetyControls->max_runs_per_minute : 60;
        $rateLimitAllowed = RateLimiter::attempt(
            key: "ai:org:{$organizationId}:runs_per_minute",
            maxAttempts: $maxRunsPerMinute,
            callback: fn () => true,
            decaySeconds: 60,
        );

        if (! $rateLimitAllowed) {
            $this->fencedTerminalRunTransition(
                $organizationId,
                $runId,
                $workerLeaseToken,
                AiRunStatus::Failed,
                AiErrorCategory::RateLimited,
                "Organization rate limit of {$maxRunsPerMinute} runs/minute exceeded."
            );

            return new AiRunResult(
                runId: $run->id,
                status: AiRunStatus::Failed,
                errorCategory: AiErrorCategory::RateLimited,
                errorMessageSanitized: "Organization rate limit of {$maxRunsPerMinute} runs/minute exceeded.",
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

        // 4. RESOLVE CANDIDATES STRICTLY FROM IMMUTABLE ACTIVE RELEASE
        $modelConfigs = AiModelConfiguration::query()
            ->where('organization_id', $organizationId)
            ->where('is_enabled', true)
            ->where('lifecycle_status', 'active')
            ->with(['providerConfiguration.credential', 'activeRelease'])
            ->orderBy('failover_priority', 'asc')
            ->get();

        $candidates = [];
        foreach ($modelConfigs as $config) {
            $release = $config->activeRelease;
            if ($release === null || $release->status !== 'active') {
                continue;
            }

            if (! in_array($run->capability->value, $release->capabilities, true)) {
                continue;
            }

            $providerConfig = $config->providerConfiguration;
            if ($providerConfig === null || ! $providerConfig->is_enabled) {
                continue;
            }

            if ($safetyControls !== null && ! $safetyControls->isProviderEnabled($providerConfig->provider_name)) {
                continue;
            }

            $credential = $providerConfig->credential;
            if ($credential === null || $credential->status !== CredentialStatus::Active) {
                continue;
            }

            $candidates[] = [
                'provider' => $release->provider_name,
                'model' => $release->model_name,
                'release' => $release,
                'credential' => $credential,
                'credential_id' => $credential->id,
                'credential_revision' => $credential->revision_id,
                'pricing' => $release->getPricingSnapshot(),
                'failover_priority' => $config->failover_priority,
            ];
        }

        // Fail closed if no valid immutable candidate exists
        if (empty($candidates)) {
            $fenced = $this->fencedTerminalRunTransition(
                $organizationId,
                $runId,
                $workerLeaseToken,
                AiRunStatus::Failed,
                AiErrorCategory::ProviderUnavailable,
                "No enabled AI provider or model release configured for capability '{$run->capability->value}'."
            );

            if (! $fenced) {
                return new AiRunResult(
                    runId: $run->id,
                    status: AiRunStatus::Failed,
                    errorCategory: AiErrorCategory::InternalError,
                    errorMessageSanitized: 'Stale worker lease lost before candidate selection.',
                );
            }

            throw new AiProviderUnavailableException("No enabled AI provider or model configured for capability '{$run->capability->value}'.");
        }

        $configuredTimeout = $promptVersion?->getParameterConfig()->timeoutSeconds ?? $capabilityDef->defaultTimeoutSeconds;
        $attemptTimeoutSeconds = min($configuredTimeout, $capabilityDef->maxTimeoutSeconds, self::PLATFORM_MAX_TIMEOUT_SECONDS);

        // 5. RESOLVE EFFECTIVE TOOLS (capability allowed ∩ prompt allowed \ safety disabled)
        $capabilityAllowedTools = $capabilityDef->allowedTools;
        $promptAllowedTools = $promptVersion !== null ? $promptVersion->allowed_tools : $capabilityAllowedTools;
        $safetyDisabledTools = $safetyControls !== null ? $safetyControls->disabled_tools : [];
        $effectiveToolNames = array_values(array_diff(array_intersect($capabilityAllowedTools, $promptAllowedTools), $safetyDisabledTools));

        $maxToolCalls = $safetyControls !== null ? $safetyControls->max_tool_calls_per_run : 10;
        $resolvedSdkTools = [];

        foreach ($effectiveToolNames as $toolName) {
            $domainTool = $this->toolRegistry->get($toolName);
            if ($domainTool instanceof SearchKnowledgeBaseTool) {
                $resolvedSdkTools[] = new SearchKnowledgeBaseSdkTool(
                    organizationId: $organizationId,
                    runId: $run->id,
                    domainTool: $domainTool,
                    maxToolCalls: $maxToolCalls,
                );
            }
        }

        // 6. RESOLVE MAX TOKENS PER RUN
        $tokenCeiling = min(
            $capabilityDef->defaultMaxTokens,
            $promptVersion?->getParameterConfig()->maxTokens ?? PHP_INT_MAX,
            $safetyControls !== null ? $safetyControls->max_tokens_per_run : PHP_INT_MAX,
        );

        $maxAttempts = $safetyControls !== null ? $safetyControls->max_failover_attempts : 3;
        $attemptNumber = (int) AiRunAttempt::query()
            ->where('organization_id', $organizationId)
            ->where('ai_run_id', $runId)
            ->max('attempt_number');
        $today = Carbon::now()->toDateString();
        $iterationCount = 0;

        $lastErrorCategory = null;
        $lastErrorMessage = null;

        foreach ($candidates as $candidate) {
            $iterationCount++;
            if ($iterationCount > $maxAttempts) {
                break;
            }
            $attemptNumber++;

            /** @var AiModelRelease $release */
            $release = $candidate['release'];
            /** @var AiPricingSnapshot $pricing */
            $pricing = $candidate['pricing'];

            $worstCaseEstimatedCost = $pricing->calculateCostMinorUnits($tokenCeiling, $tokenCeiling);

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
                        'model_release_id' => $release->id,
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
            } catch (AiBudgetExceededException) {
                $this->fencedTerminalRunTransition(
                    $organizationId,
                    $runId,
                    $workerLeaseToken,
                    AiRunStatus::Failed,
                    AiErrorCategory::BudgetExceeded,
                    'Daily spend budget for organization exceeded.'
                );

                return new AiRunResult(
                    runId: $run->id,
                    status: AiRunStatus::Failed,
                    errorCategory: AiErrorCategory::BudgetExceeded,
                    errorMessageSanitized: 'Daily spend budget for organization exceeded.',
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

                $sanitized = AiErrorSanitizer::sanitize($e);
                $lastErrorCategory = $sanitized['category'];
                $lastErrorMessage = $sanitized['message'];

                continue;
            }

            if ($attempt === null) {
                continue;
            }

            $startTime = microtime(true);
            $outputText = null;

            // 7. ISOLATED PROVIDER INVOCATION WITH EXACT CREDENTIAL & TIMEOUT
            try {
                $agent = (new DynamicWorkflowAgent(
                    instructionsText: $decryptedSystemPrompt ?: 'You are a clinical wellness AI assistant.',
                    agentTools: $resolvedSdkTools,
                    defaultProvider: $candidate['provider'],
                    defaultModel: $candidate['model'],
                ))->withMaxTokens($tokenCeiling);

                $textProvider = $this->providerFactory->createTextProvider(
                    providerName: $candidate['provider'],
                    credential: $candidate['credential'],
                    agent: $agent,
                );

                $agentPrompt = new AgentPrompt(
                    agent: $agent,
                    prompt: $decryptedUserPrompt ?: 'Hello',
                    attachments: [],
                    provider: $textProvider,
                    model: $candidate['model'],
                    timeout: $attemptTimeoutSeconds,
                );

                $response = $textProvider->prompt($agentPrompt);
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
                        'model_release_id' => $release->id,
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
                $sanitized = AiErrorSanitizer::sanitize($e);
                $lastErrorCategory = $sanitized['category'];
                $lastErrorMessage = $sanitized['message'];

                // External call started and failed/timed out: charge conservatively
                if ($attempt->status === 'running') {
                    $this->budgetManager->chargeAttemptConservatively($attempt);
                    $attempt->update([
                        'status' => 'failed',
                        'latency_ms' => $elapsedMs,
                        'error_category' => $sanitized['category'],
                        'error_message_sanitized' => $sanitized['message'],
                        'finished_at' => Carbon::now(),
                    ]);
                }
            }
        }

        // 8. ALL CANDIDATES FAILED OR TIMED OUT — FENCED TERMINAL WRITE
        $finalized = $this->fencedTerminalRunTransition(
            $organizationId,
            $runId,
            $workerLeaseToken,
            AiRunStatus::Failed,
            $lastErrorCategory ?? AiErrorCategory::ProviderUnavailable,
            $lastErrorMessage ?? 'All provider attempts failed or timed out.'
        );

        if (! $finalized) {
            return new AiRunResult(
                runId: $run->id,
                status: AiRunStatus::Failed,
                errorCategory: AiErrorCategory::InternalError,
                errorMessageSanitized: 'Stale worker lease lost during provider attempts.',
            );
        }

        return new AiRunResult(
            runId: $run->id,
            status: AiRunStatus::Failed,
            errorCategory: $lastErrorCategory ?? AiErrorCategory::ProviderUnavailable,
            errorMessageSanitized: $lastErrorMessage ?? 'All provider attempts failed or timed out.',
        );
    }

    private function fencedTerminalRunTransition(
        int $organizationId,
        int $runId,
        string $workerLeaseToken,
        AiRunStatus $status,
        ?AiErrorCategory $errorCategory = null,
        ?string $sanitizedErrorMessage = null,
    ): bool {
        return (bool) DB::transaction(function () use ($organizationId, $runId, $workerLeaseToken, $status, $errorCategory, $sanitizedErrorMessage): bool {
            $lockedRun = AiRun::query()
                ->where('organization_id', $organizationId)
                ->where('id', $runId)
                ->lockForUpdate()
                ->first();

            if ($lockedRun === null || $lockedRun->worker_lease_token !== $workerLeaseToken || $lockedRun->status !== AiRunStatus::Running) {
                return false;
            }

            $lockedRun->update([
                'status' => $status,
                'error_category' => $errorCategory,
                'error_message_sanitized' => $sanitizedErrorMessage,
                'finished_at' => Carbon::now(),
            ]);

            return true;
        });
    }
}
