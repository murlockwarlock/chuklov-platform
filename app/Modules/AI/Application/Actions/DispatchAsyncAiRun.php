<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Data\AiRunRequest;
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
use App\Modules\AI\Domain\ValueObjects\AiContextPolicy;
use App\Modules\AI\Domain\ValueObjects\AiTokenUsage;
use App\Modules\AI\Infrastructure\Jobs\ProcessAiRunJob;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Carbon\Carbon;
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
    ) {}

    public function handle(?User $actor, AiRunRequest $request): AiRun
    {
        $organization = $this->context->organization();

        if ($actor !== null) {
            $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewAiRuns);
        }

        $capabilityDef = AiCapabilityRegistry::get($request->capability);
        $timeoutSeconds = $request->timeoutSeconds ?? $capabilityDef->defaultTimeoutSeconds;
        $leaseTtl = $timeoutSeconds + max(60, $timeoutSeconds);
        $workerLeaseToken = (string) Str::uuid();

        $promptVersion = null;
        if ($request->promptVersionId !== null) {
            $promptVersion = AiPromptVersion::query()
                ->where('organization_id', $organization->getKey())
                ->where('id', $request->promptVersionId)
                ->first();
        } else {
            $prompt = AiPrompt::query()
                ->where('organization_id', $organization->getKey())
                ->where('capability', $request->capability)
                ->first();

            if ($prompt !== null && $prompt->active_version_id !== null) {
                $promptVersion = AiPromptVersion::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('id', $prompt->active_version_id)
                    ->first();
            }
        }

        $contextPolicy = $promptVersion !== null
            ? $promptVersion->getContextPolicy()
            : new AiContextPolicy(includeRag: $capabilityDef->supportsRag);

        $contextAssembly = $this->contextAssembler->assemble(
            organizationId: (int) $organization->getKey(),
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

        /** @var AiRun $run */
        $run = DB::transaction(function () use (
            $organization,
            $request,
            $promptVersion,
            $renderedPromptDigest,
            $contextAssembly,
            $workerLeaseToken,
            $leaseTtl,
            $keyVersion,
            $renderedSystemPrompt,
            $renderedUserPrompt,
        ): AiRun {
            $run = new AiRun([
                'organization_id' => $organization->getKey(),
                'capability' => $request->capability,
                'workflow_key' => $request->workflowKey,
                'origin' => $request->origin,
                'initiated_by_user_id' => $request->initiatedByUserId,
                'client_id' => $request->clientId,
                'status' => AiRunStatus::Queued,
                'execution_mode' => AiExecutionMode::Async,
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
                'worker_lease_token' => $workerLeaseToken,
                'worker_lease_expires_at' => Carbon::now()->addSeconds($leaseTtl),
                'queued_at' => Carbon::now(),
            ]);
            $run->save();

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
                    'configuration_key' => 'default',
                ]);
            }

            return $run;
        });

        ProcessAiRunJob::dispatch(
            organizationId: $organization->getKey(),
            runId: $run->id,
        );

        return $run;
    }
}
