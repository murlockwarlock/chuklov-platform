<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Application\Validation\AiInputReferenceValidator;
use App\Modules\AI\Domain\Contracts\AiContextAssemblerInterface;
use App\Modules\AI\Domain\Contracts\AiPromptRendererInterface;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\AI\Domain\Models\AiRunRagReference;
use App\Modules\AI\Domain\Registry\AiCapabilityRegistry;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use App\Modules\AI\Domain\ValueObjects\AiTokenUsage;
use App\Modules\AI\Infrastructure\Jobs\ProcessAiRunJob;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DispatchAsyncAiRun
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly AiContextAssemblerInterface $contextAssembler,
        private readonly AiPromptRendererInterface $promptRenderer,
        private readonly MedicalEncryptorInterface $medicalEncryptor,
        private readonly AiInputReferenceValidator $inputReferenceValidator,
    ) {}

    public function handle(?User $actor, AiRunRequest $request): AiRun
    {
        $organization = $this->context->organization();

        if ($actor !== null) {
            $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewAiRuns);
        }

        $capabilityDef = AiCapabilityRegistry::get($request->capability);
        $this->inputReferenceValidator->validate(
            organizationId: (int) $organization->getKey(),
            capability: $request->capability,
            references: $request->inputReferences,
            clientId: $request->clientId,
        );

        if ($request->idempotencyKey !== null) {
            $existing = AiRun::query()
                ->where('organization_id', $organization->getKey())
                ->where('idempotency_key', $request->idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $promptVersion = null;
        if ($request->promptVersionId !== null) {
            $promptVersion = AiPromptVersion::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($request->promptVersionId)
                ->first();
        } else {
            $prompt = AiPrompt::query()
                ->where('organization_id', $organization->getKey())
                ->where('capability', $request->capability)
                ->whereNotNull('active_version_id')
                ->latest('id')
                ->first();

            if ($prompt !== null && $prompt->active_version_id !== null) {
                $promptVersion = AiPromptVersion::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($prompt->active_version_id)
                    ->first();
            }
        }

        if ($promptVersion === null) {
            throw new \InvalidArgumentException('Asynchronous AI execution requires a tenant-owned active prompt version.');
        }

        if ($promptVersion->prompt === null || $promptVersion->prompt->capability !== $request->capability) {
            throw new \InvalidArgumentException('The selected prompt version does not support this capability.');
        }

        if ($promptVersion->status->value === 'draft') {
            throw new \InvalidArgumentException('Draft prompt versions cannot execute asynchronously.');
        }

        $executionDeadlineAt = Carbon::now()->addSeconds(AiRuntimeLimits::wholeRunSeconds());
        $leaseExpiresAt = $executionDeadlineAt->copy()->addSeconds(AiRuntimeLimits::PLATFORM_LEASE_GRACE_SECONDS);
        $workerLeaseToken = (string) Str::uuid();
        $contextPolicy = $promptVersion->getContextPolicy();

        $contextAssembly = $this->contextAssembler->assemble(
            organizationId: (int) $organization->getKey(),
            policy: $contextPolicy,
            inputVariables: $request->inputVariables,
            inputReferences: $request->inputReferences,
            actor: $actor,
        );

        $systemPromptTemplate = $promptVersion->system_prompt;
        $userPromptTemplate = $promptVersion->user_prompt_template;

        $renderedSystemPrompt = $this->promptRenderer->render($systemPromptTemplate, $contextAssembly->variables);
        $renderedUserPrompt = $this->promptRenderer->render($userPromptTemplate, $contextAssembly->variables);
        AiRuntimeLimits::assertRenderedPromptWithinLimit($renderedSystemPrompt, $renderedUserPrompt, $capabilityDef);
        $renderedPromptDigest = hash('sha256', $renderedSystemPrompt."\n---\n".$renderedUserPrompt);
        $keyVersion = (int) Config::get('medical.key_version', 1);
        $created = false;

        try {
            /** @var AiRun $run */
            $run = DB::transaction(function () use (
                &$created,
                $organization,
                $request,
                $promptVersion,
                $renderedPromptDigest,
                $contextAssembly,
                $workerLeaseToken,
                $executionDeadlineAt,
                $leaseExpiresAt,
                $keyVersion,
                $renderedSystemPrompt,
                $renderedUserPrompt,
                $actor,
            ): AiRun {
                if ($request->idempotencyKey !== null) {
                    $existing = AiRun::query()
                        ->where('organization_id', $organization->getKey())
                        ->where('idempotency_key', $request->idempotencyKey)
                        ->first();

                    if ($existing !== null) {
                        return $existing;
                    }
                }

                $run = new AiRun([
                    'organization_id' => $organization->getKey(),
                    'capability' => $request->capability,
                    'workflow_key' => $request->workflowKey,
                    'origin' => $request->origin,
                    'initiated_by_user_id' => $request->initiatedByUserId ?? $actor?->getKey(),
                    'client_id' => $request->clientId,
                    'status' => AiRunStatus::Queued,
                    'execution_mode' => AiExecutionMode::Async,
                    'prompt_id' => $promptVersion->prompt_id,
                    'prompt_version_id' => $promptVersion->id,
                    'model_release_id' => $request->modelReleaseId,
                    'input_references' => array_map(static fn ($reference): array => $reference->toArray(), $request->inputReferences),
                    'rendered_prompt_digest' => $renderedPromptDigest,
                    'context_provenance' => $contextAssembly->provenanceSummary,
                    'structured_output_schema_version' => $promptVersion->output_schema ? 'v1' : null,
                    'structured_output_valid' => true,
                    'token_usage' => (new AiTokenUsage)->toArray(),
                    'cost_currency' => 'USD',
                    'idempotency_key' => $request->idempotencyKey,
                    'worker_lease_token' => $workerLeaseToken,
                    'worker_lease_expires_at' => $leaseExpiresAt,
                    'execution_deadline_at' => $executionDeadlineAt,
                    'queued_at' => Carbon::now(),
                ]);
                $run->save();
                $created = true;

                $payload = new AiRunPayload([
                    'organization_id' => $organization->getKey(),
                    'ai_run_id' => $run->id,
                    'encryption_key_version' => $keyVersion,
                    'encrypted_system_prompt' => $this->medicalEncryptor->encryptField((int) $organization->getKey(), $renderedSystemPrompt, $keyVersion),
                    'encrypted_user_prompt' => $this->medicalEncryptor->encryptField((int) $organization->getKey(), $renderedUserPrompt, $keyVersion),
                ]);
                $payload->save();

                foreach ($contextAssembly->ragChunks as $index => $ragChunk) {
                    AiRunRagReference::create([
                        'organization_id' => $organization->getKey(),
                        'ai_run_id' => $run->id,
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

                return $run;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if ($request->idempotencyKey === null) {
                throw $exception;
            }

            $run = AiRun::query()
                ->where('organization_id', $organization->getKey())
                ->where('idempotency_key', $request->idempotencyKey)
                ->first()
                ?? throw $exception;
        }

        if ($created) {
            ProcessAiRunJob::dispatch(
                organizationId: $organization->getKey(),
                runId: $run->id,
            );
        }

        return $run;
    }
}
