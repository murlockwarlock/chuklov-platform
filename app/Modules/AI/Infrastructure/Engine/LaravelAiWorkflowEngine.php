<?php

namespace App\Modules\AI\Infrastructure\Engine;

use App\Models\User;
use App\Modules\AI\Application\Actions\PrepareAiRun;
use App\Modules\AI\Application\Actions\ResolveAiExecutionCandidates;
use App\Modules\AI\Application\Attachments\AiAttachmentResolver;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Application\Data\AiRunResult;
use App\Modules\AI\Application\Validation\AiInputReferenceValidator;
use App\Modules\AI\Domain\Contracts\AiContextAssemblerInterface;
use App\Modules\AI\Domain\Contracts\AiOutputValidatorInterface;
use App\Modules\AI\Domain\Contracts\AiPricingCalculatorInterface;
use App\Modules\AI\Domain\Contracts\AiPromptRendererInterface;
use App\Modules\AI\Domain\Contracts\AiSafetyBudgetManagerInterface;
use App\Modules\AI\Domain\Contracts\AiToolRegistryInterface;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiErrorCategory;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\BudgetReservationStatus;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Exceptions\AiBudgetExceededException;
use App\Modules\AI\Domain\Exceptions\AiKillSwitchException;
use App\Modules\AI\Domain\Exceptions\AiProviderUnavailableException;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunAttempt;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\AI\Domain\Registry\AiCapabilityRegistry;
use App\Modules\AI\Domain\Services\AiErrorSanitizer;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use App\Modules\AI\Domain\ValueObjects\AiInputReference;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Domain\ValueObjects\AiRunExecutionContext;
use App\Modules\AI\Domain\ValueObjects\AiTokenUsage;
use App\Modules\AI\Infrastructure\Providers\AiProviderExecutionConfiguration;
use App\Modules\AI\Infrastructure\Providers\AiProviderFactory;
use App\Modules\AI\Infrastructure\Tools\SearchKnowledgeBaseSdkTool;
use App\Modules\AI\Infrastructure\Tools\SearchKnowledgeBaseTool;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingExecutionSnapshot;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
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
        private readonly AiProviderFactory $providerFactory,
        private readonly AiInputReferenceValidator $inputReferenceValidator,
        private readonly PrepareAiRun $prepareAiRun,
        private readonly ResolveAiExecutionCandidates $candidateResolver,
        private readonly AiAttachmentResolver $attachmentResolver,
    ) {}

    public function run(int $organizationId, AiRunRequest $request): AiRunResult
    {
        $executionDeadlineAt = Carbon::now()->addSeconds(AiRuntimeLimits::wholeRunSeconds());
        $capabilityDef = AiCapabilityRegistry::get($request->capability);

        $this->inputReferenceValidator->validate(
            organizationId: $organizationId,
            capability: $request->capability,
            references: $request->inputReferences,
            clientId: $request->clientId,
        );

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
                ->whereNotNull('active_version_id')
                ->latest('id')
                ->first();

            if ($prompt !== null && $prompt->active_version_id !== null) {
                $promptVersion = AiPromptVersion::query()
                    ->where('organization_id', $organizationId)
                    ->where('id', $prompt->active_version_id)
                    ->first();
            }
        }

        if ($promptVersion === null) {
            throw new InvalidArgumentException('AI execution requires a tenant-owned active prompt version.');
        }

        if ($promptVersion->prompt === null || $promptVersion->prompt->capability !== $request->capability) {
            throw new InvalidArgumentException('The selected prompt version does not support this capability.');
        }

        if ($promptVersion->status->value === 'draft' && $request->executionMode !== AiExecutionMode::Playground) {
            throw new InvalidArgumentException('Draft prompt versions are only executable in the playground.');
        }

        $contextPolicy = $promptVersion->getContextPolicy();
        $maxToolCallsForReservation = $contextPolicy->allows('rag')
            && in_array('search_knowledge_base', array_intersect($capabilityDef->allowedTools, $promptVersion->allowed_tools), true)
            && ($safetyControls === null || ! in_array('search_knowledge_base', $safetyControls->disabled_tools, true))
            ? AiRuntimeLimits::effectiveMaxToolCalls($capabilityDef, $safetyControls?->max_tool_calls_per_run)
            : 0;

        $claim = $this->prepareAiRun->claim(
            organizationId: $organizationId,
            request: $request,
            promptVersion: $promptVersion,
            contextPolicy: $contextPolicy,
            executionDeadlineAt: $executionDeadlineAt,
            maxToolCalls: $maxToolCallsForReservation,
        );
        $run = $claim['run'];
        $leaseToken = $claim['worker_lease_token'];
        if (! $claim['created']) {
            return new AiRunResult(
                runId: $run->id,
                status: $run->status,
                tokenUsage: $run->getTokenUsage(),
                settledEstimatedCostMinorUnits: $run->settled_estimated_cost_minor_units ?? 0,
                errorCategory: $run->error_category,
                errorMessageSanitized: $run->error_message_sanitized,
            );
        }

        try {
            $embeddingSnapshot = null;
            if (($contextPolicy->includeRag && AiRuntimeLimits::ragQuery($request->inputVariables) !== '') || $maxToolCallsForReservation > 0) {
                $provenance = is_array($run->context_provenance ?? null) ? $run->context_provenance : [];
                $embedding = is_array($provenance['retrieval_embedding'] ?? null)
                    ? $provenance['retrieval_embedding']
                    : [];
                $embeddingSnapshot = EmbeddingExecutionSnapshot::fromArray($embedding);
            }
            $contextAssembly = $this->contextAssembler->assemble(
                organizationId: $organizationId,
                policy: $contextPolicy,
                inputVariables: $request->inputVariables,
                inputReferences: $request->inputReferences,
                actor: $request->actor,
                executionDeadlineAt: $executionDeadlineAt,
                embeddingSnapshot: $embeddingSnapshot,
                capability: $request->capability,
            );
            $renderedSystemPrompt = $this->promptRenderer->render($promptVersion->system_prompt, $contextAssembly->variables);
            $renderedUserPrompt = $this->promptRenderer->render($promptVersion->user_prompt_template, $contextAssembly->variables);
            AiRuntimeLimits::assertRenderedPromptWithinLimit($renderedSystemPrompt, $renderedUserPrompt, $capabilityDef);
            $renderedPromptDigest = hash('sha256', $renderedSystemPrompt."\n---\n".$renderedUserPrompt);
            if (! $this->prepareAiRun->complete(
                run: $run,
                contextAssembly: $contextAssembly,
                renderedSystemPrompt: $renderedSystemPrompt,
                renderedUserPrompt: $renderedUserPrompt,
                renderedPromptDigest: $renderedPromptDigest,
                keyVersion: (int) Config::get('medical.key_version', 1),
            )) {
                $freshRun = $run->fresh() ?? $run;

                return new AiRunResult(
                    runId: $freshRun->id,
                    status: $freshRun->status,
                    tokenUsage: $freshRun->getTokenUsage(),
                    errorCategory: $freshRun->error_category,
                    errorMessageSanitized: $freshRun->error_message_sanitized,
                );
            }
        } catch (Throwable $e) {
            $sanitized = AiErrorSanitizer::sanitize($e);
            $this->prepareAiRun->fail($run, $sanitized['message']);
            throw $e;
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

        if ($run->execution_deadline_at !== null && ! AiRuntimeLimits::deadlineIsActive($run->execution_deadline_at)) {
            return new AiRunResult(
                runId: $run->id,
                status: AiRunStatus::TimedOut,
                errorCategory: AiErrorCategory::ProviderUnavailable,
                errorMessageSanitized: 'Whole-run execution deadline expired before provider execution.',
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
        $maxRunsPerMinute = AiRuntimeLimits::effectiveRunsPerMinute($safetyControls?->max_runs_per_minute);
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
            : null;
        $decryptedUserPrompt = $payload?->encrypted_user_prompt !== null
            ? $this->medicalEncryptor->decryptField($organizationId, $payload->encrypted_user_prompt, $keyVersion)
            : null;

        $promptVersion = $run->prompt_version_id !== null
            ? AiPromptVersion::query()->where('organization_id', $organizationId)->where('id', $run->prompt_version_id)->first()
            : null;
        if ($promptVersion === null
            || $promptVersion->prompt === null
            || $promptVersion->prompt->capability !== $run->capability
            || ($promptVersion->status->value === 'draft' && $run->execution_mode !== AiExecutionMode::Playground)
            || ! is_string($decryptedSystemPrompt)
            || trim($decryptedSystemPrompt) === ''
            || ! is_string($decryptedUserPrompt)
            || $payload === null) {
            $this->fencedTerminalRunTransition(
                $organizationId,
                $runId,
                $workerLeaseToken,
                AiRunStatus::Failed,
                AiErrorCategory::InternalError,
                'Immutable prompt version or protected prompt payload is unavailable.',
            );

            return new AiRunResult(
                runId: $run->id,
                status: AiRunStatus::Failed,
                errorCategory: AiErrorCategory::InternalError,
                errorMessageSanitized: 'Immutable prompt version or protected prompt payload is unavailable.',
            );
        }

        $contextPolicy = $promptVersion->getContextPolicy();

        $inputReferences = array_values(array_map(
            static fn (mixed $reference): AiInputReference => $reference instanceof AiInputReference
                ? $reference
                : AiInputReference::fromArray((array) $reference),
            (array) $run->input_references,
        ));
        $attachmentResolution = ['files' => [], 'provenance' => []];
        $attachmentActor = null;
        if (($run->capability === AiCapability::PostureAnalysis && $run->execution_mode !== AiExecutionMode::Evaluation)
            || array_filter($inputReferences, static fn (AiInputReference $reference): bool => $reference->type === 'medical_attachment') !== []) {
            try {
                $attachmentActor = $run->initiated_by_user_id !== null
                    ? User::query()->whereKey($run->initiated_by_user_id)->first()
                    : null;
                $attachmentResolution = $this->attachmentResolver->resolve(
                    organizationId: $organizationId,
                    capability: $run->capability,
                    references: $inputReferences,
                    actor: $attachmentActor,
                );

                $runProvenance = is_array($run->context_provenance ?? null) ? $run->context_provenance : [];
                $acceptedProvenance = is_array($runProvenance['attachments'] ?? null)
                    ? $runProvenance['attachments']
                    : null;
                if ($inputReferences !== []
                    && $attachmentResolution['provenance'] !== []
                    && $acceptedProvenance !== $attachmentResolution['provenance']) {
                    throw new InvalidArgumentException('Accepted medical attachment provenance no longer matches the source.');
                }
            } catch (Throwable) {
                $this->fencedTerminalRunTransition(
                    $organizationId,
                    $runId,
                    $workerLeaseToken,
                    AiRunStatus::Failed,
                    AiErrorCategory::ProviderUnavailable,
                    'Protected attachment input is unavailable or unauthorized.',
                );

                return new AiRunResult(
                    runId: $run->id,
                    status: AiRunStatus::Failed,
                    errorCategory: AiErrorCategory::ProviderUnavailable,
                    errorMessageSanitized: 'Protected attachment input is unavailable or unauthorized.',
                );
            }
        }

        $maxAttempts = AiRuntimeLimits::effectiveMaxFailoverAttempts($safetyControls?->max_failover_attempts);
        $candidates = $run->execution_mode === AiExecutionMode::Async
            ? $this->candidateResolver->resolveSnapshot($organizationId, $run, $safetyControls)
            : [];

        if ($run->execution_mode !== AiExecutionMode::Async && $run->model_release_id !== null) {
            $exactRelease = null;
            $exactRelease = AiModelRelease::query()
                ->where('organization_id', $organizationId)
                ->whereKey($run->model_release_id)
                ->with(['modelConfiguration.providerConfiguration.credential'])
                ->first();

            $modelConfigs = $exactRelease?->modelConfiguration !== null
                ? collect([$exactRelease->modelConfiguration])
                : collect();
        } elseif ($run->execution_mode !== AiExecutionMode::Async) {
            $modelConfigs = AiModelConfiguration::query()
                ->where('organization_id', $organizationId)
                ->where('is_enabled', true)
                ->where('lifecycle_status', 'active')
                ->whereHas('activeRelease', static function (Builder $query) use ($run): void {
                    $query
                        ->where('status', 'active')
                        ->whereJsonContains('capabilities', $run->capability->value);
                })
                ->whereHas('providerConfiguration', static function (Builder $query) use ($safetyControls): void {
                    $query
                        ->where('is_enabled', true)
                        ->where('health_status', ProviderHealthStatus::Healthy->value);

                    if ($safetyControls !== null && $safetyControls->disabled_providers !== []) {
                        $query->whereNotIn('provider_name', $safetyControls->disabled_providers);
                    }

                    $query->whereHas('credential', static function (Builder $credentialQuery): void {
                        $credentialQuery
                            ->where('status', CredentialStatus::Active->value)
                            ->whereColumn('organization_credentials.provider', 'ai_provider_configurations.provider_name')
                            ->whereColumn('organization_credentials.revision_id', 'ai_provider_configurations.tested_credential_revision');
                    });
                })
                ->with(['providerConfiguration.credential', 'activeRelease'])
                ->orderBy('failover_priority', 'asc')
                ->orderBy('id', 'asc')
                ->limit(AiRuntimeLimits::PLATFORM_MAX_MODEL_CONFIGURATION_SCAN)
                ->get();
        }

        foreach ($run->execution_mode !== AiExecutionMode::Async ? $modelConfigs : [] as $config) {
            if (count($candidates) >= $maxAttempts) {
                break;
            }

            $release = $run->model_release_id !== null
                ? ($exactRelease ?? null)
                : $config->activeRelease;
            $allowedReleaseStatuses = $run->model_release_id !== null ? ['active', 'retired'] : ['active'];
            if ($release === null || ! in_array($release->status, $allowedReleaseStatuses, true)) {
                continue;
            }

            if (! in_array($run->capability->value, $release->capabilities, true)) {
                continue;
            }

            $providerConfig = $config->providerConfiguration;
            if ($providerConfig === null
                || $providerConfig->provider_name !== $release->provider_name
                || ! $providerConfig->is_enabled
                || $providerConfig->health_status !== ProviderHealthStatus::Healthy) {
                continue;
            }

            if ($safetyControls !== null && ! $safetyControls->isProviderEnabled($providerConfig->provider_name)) {
                continue;
            }

            $credential = $providerConfig->credential;
            if ($credential === null
                || $credential->provider !== $providerConfig->provider_name
                || $credential->status !== CredentialStatus::Active
                || $credential->revision_id === null
                || $providerConfig->tested_credential_revision === null) {
                continue;
            }

            try {
                $configurationDigest = AiProviderExecutionConfiguration::digest(
                    $providerConfig->provider_name,
                    $providerConfig->options ?? [],
                );
            } catch (Throwable) {
                continue;
            }

            if ($providerConfig->tested_credential_revision !== $credential->revision_id
                || $providerConfig->tested_configuration_digest !== $configurationDigest) {
                continue;
            }

            $pricing = $release->getPricingSnapshot();
            if (! $pricing->isComplete()) {
                continue;
            }

            $candidates[] = [
                'provider' => $release->provider_name,
                'model' => $release->model_name,
                'release' => $release,
                'credential' => $credential,
                'credential_id' => $credential->id,
                'credential_revision' => $credential->revision_id,
                'provider_configuration_id' => $providerConfig->id,
                'provider_configuration_digest' => $configurationDigest,
                'pricing' => $pricing,
                'failover_priority' => $config->failover_priority,
            ];
        }

        if ($attachmentResolution['files'] !== []) {
            $requiredModalities = [];
            foreach ($attachmentResolution['files'] as $file) {
                $modality = match (true) {
                    $file instanceof StoredDocument => AiModelModality::DocumentInput,
                    $file instanceof StoredImage => AiModelModality::ImageInput,
                    default => null,
                };

                if ($modality === null) {
                    $requiredModalities = null;
                    break;
                }

                $requiredModalities[$modality->value] = $modality;
            }

            if ($requiredModalities === null) {
                $candidates = [];
            } else {
                $candidates = array_values(array_filter(
                    $candidates,
                    fn (array $candidate): bool => AiProviderFactory::supportsAttachments(
                        providerName: (string) $candidate['provider'],
                        release: $candidate['release'],
                        requiredModalities: array_values($requiredModalities),
                    ),
                ));
            }
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

        $configuredTimeout = $promptVersion->getParameterConfig()->timeoutSeconds;
        $attemptTimeoutSeconds = AiRuntimeLimits::effectiveTimeout(
            requestedTimeout: $configuredTimeout,
            capabilityMaxTimeout: $capabilityDef->maxTimeoutSeconds,
            organizationTimeout: $safetyControls?->default_timeout_seconds,
        );

        // 5. RESOLVE EFFECTIVE TOOLS (capability allowed ∩ prompt allowed \ safety disabled)
        $capabilityAllowedTools = $capabilityDef->allowedTools;
        $promptAllowedTools = $promptVersion->allowed_tools;
        $safetyDisabledTools = $safetyControls !== null ? $safetyControls->disabled_tools : [];
        $effectiveToolNames = array_values(array_diff(array_intersect($capabilityAllowedTools, $promptAllowedTools), $safetyDisabledTools));
        if (! $contextPolicy->allows('rag')) {
            $effectiveToolNames = array_values(array_diff($effectiveToolNames, ['search_knowledge_base']));
        }

        $maxToolCalls = AiRuntimeLimits::effectiveMaxToolCalls(
            capability: $capabilityDef,
            organizationMaxToolCalls: $safetyControls?->max_tool_calls_per_run,
        );
        $resolvedSdkTools = [];

        foreach ($effectiveToolNames as $toolName) {
            $domainTool = $this->toolRegistry->get($toolName);
            if ($domainTool instanceof SearchKnowledgeBaseTool) {
                $resolvedSdkTools[] = new SearchKnowledgeBaseSdkTool(
                    executionContext: new AiRunExecutionContext(
                        organizationId: $organizationId,
                        aiRunId: $run->id,
                        workerLeaseToken: $workerLeaseToken,
                        executionDeadlineAt: $run->execution_deadline_at,
                    ),
                    domainTool: $domainTool,
                    maxToolCalls: $maxToolCalls,
                    minimumSimilarity: $contextPolicy->ragMinSimilarity,
                    allowedKnowledgeSourceIds: $contextPolicy->ragKnowledgeSourceIds,
                    policyMaxResults: $contextPolicy->ragMaxChunks,
                );
            }
        }

        if ($resolvedSdkTools === []) {
            $maxToolCalls = 0;
        }
        $maxProviderSteps = min($capabilityDef->maxProviderSteps, AiRuntimeLimits::providerSteps($maxToolCalls));

        // 6. RESOLVE MAX TOKENS PER RUN
        $tokenCeiling = AiRuntimeLimits::effectiveMaxOutputTokens(
            capability: $capabilityDef,
            requestedMaxTokens: $promptVersion->getParameterConfig()->maxTokens,
            organizationMaxTokens: $safetyControls?->max_tokens_per_run,
        );

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

            $boundedAttemptFinish = Carbon::now()->addSeconds(
                AiRuntimeLimits::providerAttemptSeconds(
                    providerSteps: $maxProviderSteps,
                    providerStepTimeout: $attemptTimeoutSeconds,
                    toolCalls: $maxToolCalls,
                ) + AiRuntimeLimits::PLATFORM_EXECUTION_MARGIN_SECONDS,
            );
            if ($run->execution_deadline_at !== null
                && ! $boundedAttemptFinish->lessThan($run->execution_deadline_at)) {
                $lastErrorCategory = AiErrorCategory::ProviderUnavailable;
                $lastErrorMessage = 'Whole-run execution window is too short for the bounded provider attempt.';

                break;
            }

            $worstCaseExposure = AiRuntimeLimits::worstCaseProviderExposure(
                maxInputTokens: min(AiRuntimeLimits::PLATFORM_MAX_INPUT_TOKENS, $capabilityDef->maxInputTokens),
                maxOutputTokens: $tokenCeiling,
                maxToolCalls: $maxToolCalls,
                maxProviderSteps: $maxProviderSteps,
                maxRagContextTokens: $capabilityDef->maxRagContextTokens,
            );
            $worstCaseEstimatedCost = $pricing->calculateCostMinorUnits(
                promptTokens: $worstCaseExposure['input_tokens'],
                completionTokens: $worstCaseExposure['output_tokens'],
                cacheReadInputTokens: $worstCaseExposure['cache_read_input_tokens'],
                cacheWriteInputTokens: $worstCaseExposure['cache_write_input_tokens'],
                reasoningTokens: $worstCaseExposure['reasoning_tokens'],
                providerRequests: $worstCaseExposure['provider_requests'],
            );

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

                    if ($lockedRun === null
                        || $lockedRun->worker_lease_token !== $workerLeaseToken
                        || $lockedRun->status !== AiRunStatus::Running
                        || ($lockedRun->execution_deadline_at !== null && ! AiRuntimeLimits::deadlineIsActive($lockedRun->execution_deadline_at))) {
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
                        'provider_configuration_id' => $candidate['provider_configuration_id'] ?? null,
                        'provider_configuration_digest' => $candidate['provider_configuration_digest'] ?? null,
                        'worker_lease_token' => $workerLeaseToken,
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

            if ($run->execution_mode === AiExecutionMode::Async) {
                $freshCandidate = $this->candidateResolver->refreshSnapshotCandidate(
                    organizationId: $organizationId,
                    run: $run,
                    position: (int) ($candidate['snapshot_position'] ?? -1),
                );
                if ($freshCandidate === null) {
                    $attemptOwned = $this->fencedAttemptFailure(
                        organizationId: $organizationId,
                        runId: $runId,
                        attemptId: $attempt->id,
                        workerLeaseToken: $workerLeaseToken,
                        latencyMs: 0,
                        errorCategory: AiErrorCategory::ProviderUnavailable,
                        errorMessage: 'Accepted provider configuration is no longer compatible.',
                    );
                    if (! $attemptOwned) {
                        break;
                    }

                    $lastErrorCategory = AiErrorCategory::ProviderUnavailable;
                    $lastErrorMessage = 'Accepted provider configuration is no longer compatible.';

                    continue;
                }

                $candidate = $freshCandidate;
                $release = $candidate['release'];
                $pricing = $candidate['pricing'];
            }

            if ($attachmentResolution['files'] !== []) {
                try {
                    $freshAttachments = $this->attachmentResolver->resolve(
                        organizationId: $organizationId,
                        capability: $run->capability,
                        references: $inputReferences,
                        actor: $attachmentActor,
                    );
                    if ($freshAttachments['provenance'] !== $attachmentResolution['provenance']) {
                        throw new InvalidArgumentException('Protected attachment provenance changed before provider execution.');
                    }
                    $attachmentResolution = $freshAttachments;
                } catch (Throwable $e) {
                    $sanitized = AiErrorSanitizer::sanitize($e);
                    $attemptOwned = $this->fencedAttemptFailure(
                        organizationId: $organizationId,
                        runId: $runId,
                        attemptId: $attempt->id,
                        workerLeaseToken: $workerLeaseToken,
                        latencyMs: 0,
                        errorCategory: $sanitized['category'],
                        errorMessage: $sanitized['message'],
                    );
                    if (! $attemptOwned) {
                        break;
                    }

                    $lastErrorCategory = $sanitized['category'];
                    $lastErrorMessage = $sanitized['message'];

                    continue;
                }
            }

            $startTime = microtime(true);
            $outputText = null;

            // 7. ISOLATED PROVIDER INVOCATION WITH EXACT CREDENTIAL & TIMEOUT
            try {
                $agent = (new DynamicWorkflowAgent(
                    instructionsText: $decryptedSystemPrompt,
                    agentTools: $resolvedSdkTools,
                    defaultProvider: $candidate['provider'],
                    defaultModel: $candidate['model'],
                ))
                    ->withMaxTokens($tokenCeiling)
                    ->withMaxSteps($maxProviderSteps);

                $textProvider = $this->providerFactory->createTextProvider(
                    providerName: $candidate['provider'],
                    credential: $candidate['credential'],
                    agent: $agent,
                );

                $agentPrompt = new AgentPrompt(
                    agent: $agent,
                    prompt: $decryptedUserPrompt,
                    attachments: $attachmentResolution['files'],
                    provider: $textProvider,
                    model: $candidate['model'],
                    timeout: $attemptTimeoutSeconds,
                );

                $response = $textProvider->prompt($agentPrompt);
                $outputText = (string) $response;
                $actualProvider = $this->responseMetadataValue($response, 'provider', $candidate['provider'], 64);
                $actualModel = $this->responseMetadataValue($response, 'model', $candidate['model'], 120);

                $elapsedMs = (int) round((microtime(true) - $startTime) * 1000);
                $providerRequests = $this->providerRequestCountForSettlement(
                    response: $response,
                    reservedProviderRequests: $worstCaseExposure['provider_requests'],
                );

                $tokenUsage = $this->resolveTokenUsage(
                    response: $response,
                    systemPrompt: $decryptedSystemPrompt ?: '',
                    userPrompt: $decryptedUserPrompt ?: '',
                    outputText: $outputText,
                    providerRequests: $providerRequests,
                );

                $settledCost = $this->pricingCalculator->calculateEstimatedCost(
                    pricing: $pricing,
                    promptTokens: $tokenUsage->promptTokens,
                    completionTokens: $tokenUsage->completionTokens,
                    cacheReadInputTokens: $tokenUsage->cacheReadInputTokens,
                    cacheWriteInputTokens: $tokenUsage->cacheWriteInputTokens,
                    reasoningTokens: $tokenUsage->reasoningTokens,
                    providerRequests: $tokenUsage->providerRequests,
                );

                $outputPayload = null;
                $outputSchema = $promptVersion->output_schema;
                $isValid = true;

                if ($outputSchema !== null) {
                    $isValid = $this->outputValidator->validate($outputText, $outputSchema);
                    if ($isValid) {
                        $outputPayload = json_decode($outputText, true);
                    }
                }

                $encodedPayload = $outputPayload !== null ? json_encode($outputPayload) : null;
                $humanReviewStatus = ($capabilityDef->requiresHumanReview || $promptVersion->output_schema !== null)
                    ? HumanReviewStatus::PendingReview
                    : HumanReviewStatus::NotRequired;

                $validationError = ! $isValid ? $this->outputValidator->getValidationError() : null;
                $runStatus = $isValid ? AiRunStatus::Succeeded : AiRunStatus::InvalidOutput;

                $staleLoss = false;
                $accountedCost = $settledCost;

                // FENCING CHECK + ATOMIC SETTLEMENT + DURABLE FINAL STATE WRITE
                DB::transaction(function () use (
                    &$staleLoss,
                    &$accountedCost,
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
                    $release,
                    $actualProvider,
                    $actualModel,
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

                    if ($lockedRun === null
                        || $lockedRun->worker_lease_token !== $workerLeaseToken
                        || $lockedRun->status !== AiRunStatus::Running
                        || ($lockedRun->execution_deadline_at !== null && ! AiRuntimeLimits::deadlineIsActive($lockedRun->execution_deadline_at))) {
                        $staleLoss = true;

                        return;
                    }

                    $accountedCost = $this->budgetManager->settleAttemptBudget($attempt, $settledCost);
                    $accountedCost += $this->budgetManager->settleRetrievalEmbeddingBudget(
                        $lockedRun,
                        $this->retrievalEmbeddingEstimatedCost($lockedRun),
                    );

                    $attempt->update([
                        'status' => 'succeeded',
                        'latency_ms' => $elapsedMs,
                        'token_usage' => $tokenUsage->toArray(),
                        'finished_at' => Carbon::now(),
                    ]);

                    $payload->update([
                        'encrypted_output_text' => $this->medicalEncryptor->encryptField($organizationId, $outputText, $keyVersion),
                        'encrypted_output_payload' => is_string($encodedPayload)
                            ? $this->medicalEncryptor->encryptField($organizationId, $encodedPayload, $keyVersion)
                            : null,
                    ]);

                    $lockedRun->update([
                        'status' => $runStatus,
                        'actual_provider' => $actualProvider,
                        'actual_model' => $actualModel,
                        'model_release_id' => $release->id,
                        'latency_ms' => $elapsedMs,
                        'settled_estimated_cost_minor_units' => $accountedCost,
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
                    settledEstimatedCostMinorUnits: $accountedCost,
                    latencyMs: $elapsedMs,
                    errorCategory: ! $isValid ? AiErrorCategory::OutputSchemaValidationFailed : null,
                    errorMessageSanitized: $validationError,
                );
            } catch (Throwable $e) {
                $elapsedMs = (int) round((microtime(true) - $startTime) * 1000);
                $sanitized = AiErrorSanitizer::sanitize($e);
                $lastErrorCategory = $sanitized['category'];
                $lastErrorMessage = $sanitized['message'];

                // The owner transaction charges and records this attempt together. A stale worker leaves the reservation for reclaim.
                if ($attempt->status === 'running') {
                    $attemptOwned = $this->fencedAttemptFailure(
                        organizationId: $organizationId,
                        runId: $runId,
                        attemptId: $attempt->id,
                        workerLeaseToken: $workerLeaseToken,
                        latencyMs: $elapsedMs,
                        errorCategory: $sanitized['category'],
                        errorMessage: $sanitized['message'],
                    );

                    if (! $attemptOwned) {
                        break;
                    }
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

    private function resolveTokenUsage(
        mixed $response,
        string $systemPrompt,
        string $userPrompt,
        string $outputText,
        int $providerRequests,
    ): AiTokenUsage {
        $usage = is_object($response) ? ($response->usage ?? null) : null;
        if ($usage instanceof Usage) {
            $hasProviderUsage = $usage->promptTokens > 0
                || $usage->completionTokens > 0
                || $usage->cacheWriteInputTokens > 0
                || $usage->cacheReadInputTokens > 0
                || $usage->reasoningTokens > 0;

            if ($hasProviderUsage) {
                return new AiTokenUsage(
                    promptTokens: $usage->promptTokens,
                    completionTokens: $usage->completionTokens,
                    totalTokens: $usage->promptTokens + $usage->completionTokens + $usage->cacheReadInputTokens,
                    cacheWriteInputTokens: $usage->cacheWriteInputTokens,
                    cacheReadInputTokens: $usage->cacheReadInputTokens,
                    reasoningTokens: $usage->reasoningTokens,
                    providerRequests: $providerRequests,
                    usageSource: 'provider_reported',
                );
            }
        }

        $promptTokens = AiRuntimeLimits::estimateTokens($systemPrompt."\n".$userPrompt);
        $completionTokens = AiRuntimeLimits::estimateTokens($outputText);

        return new AiTokenUsage(
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $promptTokens + $completionTokens,
            providerRequests: $providerRequests,
            usageSource: 'estimated',
        );
    }

    private function providerRequestCountForSettlement(mixed $response, int $reservedProviderRequests): int
    {
        if ($response instanceof TextResponse && $response->steps->isNotEmpty()) {
            return $response->steps->count();
        }

        return $reservedProviderRequests;
    }

    private function responseMetadataValue(mixed $response, string $field, string $fallback, int $maxLength): string
    {
        if (! is_object($response) || ! isset($response->meta) || ! is_object($response->meta)) {
            return $fallback;
        }

        $value = $response->meta->{$field} ?? null;
        if (! is_string($value) || trim($value) === '' || strlen($value) > $maxLength) {
            return $fallback;
        }

        return trim($value);
    }

    private function fencedAttemptFailure(
        int $organizationId,
        int $runId,
        int $attemptId,
        string $workerLeaseToken,
        int $latencyMs,
        AiErrorCategory $errorCategory,
        string $errorMessage,
    ): bool {
        return (bool) DB::transaction(function () use (
            $organizationId,
            $runId,
            $attemptId,
            $workerLeaseToken,
            $latencyMs,
            $errorCategory,
            $errorMessage,
        ): bool {
            $lockedRun = AiRun::query()
                ->where('organization_id', $organizationId)
                ->whereKey($runId)
                ->lockForUpdate()
                ->first();

            if ($lockedRun === null) {
                return false;
            }

            $attemptQuery = AiRunAttempt::query()
                ->where('organization_id', $organizationId)
                ->whereKey($attemptId)
                ->where('ai_run_id', $runId)
                ->where('worker_lease_token', $workerLeaseToken)
                ->where('status', 'running');

            if ($lockedRun->status !== AiRunStatus::Running
                || ($lockedRun->execution_deadline_at !== null && ! AiRuntimeLimits::deadlineIsActive($lockedRun->execution_deadline_at))
                || $lockedRun->worker_lease_token !== $workerLeaseToken) {
                return false;
            }

            $attempt = AiRunAttempt::query()
                ->where('organization_id', $organizationId)
                ->whereKey($attemptId)
                ->where('ai_run_id', $runId)
                ->where('worker_lease_token', $workerLeaseToken)
                ->where('status', 'running')
                ->first();

            if ($attempt === null) {
                return false;
            }

            $this->budgetManager->chargeAttemptConservatively($attempt);

            $attemptQuery->update([
                'status' => 'failed',
                'latency_ms' => $latencyMs,
                'error_category' => $errorCategory,
                'error_message_sanitized' => $errorMessage,
                'finished_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            return true;
        });
    }

    private function retrievalEmbeddingEstimatedCost(AiRun $run): int
    {
        $provenance = is_array($run->context_provenance ?? null) ? $run->context_provenance : [];
        $retrievalEmbedding = is_array($provenance['retrieval_embedding'] ?? null)
            ? $provenance['retrieval_embedding']
            : [];

        if (($retrievalEmbedding['requires_conservative_settlement'] ?? false) === true) {
            return max(0, (int) ($retrievalEmbedding['maximum_cost_minor_units'] ?? 0));
        }

        return max(0, (int) ($retrievalEmbedding['estimated_cost_minor_units'] ?? 0));
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

            if ($lockedRun === null
                || $lockedRun->worker_lease_token !== $workerLeaseToken
                || $lockedRun->status !== AiRunStatus::Running
                || ($lockedRun->execution_deadline_at !== null && ! AiRuntimeLimits::deadlineIsActive($lockedRun->execution_deadline_at))) {
                return false;
            }

            $this->budgetManager->chargeRetrievalEmbeddingConservatively($lockedRun);

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
