<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Application\Validation\AiInputReferenceValidator;
use App\Modules\AI\Domain\Contracts\AiContextAssemblerInterface;
use App\Modules\AI\Domain\Contracts\AiPromptRendererInterface;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Registry\AiCapabilityRegistry;
use App\Modules\AI\Domain\Services\AiErrorSanitizer;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use App\Modules\AI\Infrastructure\Jobs\ProcessAiRunJob;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;

class DispatchAsyncAiRun
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly AiContextAssemblerInterface $contextAssembler,
        private readonly AiPromptRendererInterface $promptRenderer,
        private readonly AiInputReferenceValidator $inputReferenceValidator,
        private readonly PrepareAiRun $prepareAiRun,
    ) {}

    public function handle(?User $actor, AiRunRequest $request): AiRun
    {
        $executionDeadlineAt = Carbon::now()->addSeconds(AiRuntimeLimits::wholeRunSeconds());
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

        $contextPolicy = $promptVersion->getContextPolicy();
        $safetyControls = AiOrganizationSafetyControl::query()
            ->where('organization_id', $organization->getKey())
            ->first();
        $maxToolCalls = $contextPolicy->allows('rag')
            && in_array('search_knowledge_base', array_intersect($capabilityDef->allowedTools, $promptVersion->allowed_tools), true)
            && ($safetyControls === null || ! in_array('search_knowledge_base', $safetyControls->disabled_tools, true))
            ? AiRuntimeLimits::effectiveMaxToolCalls($capabilityDef, $safetyControls?->max_tool_calls_per_run)
            : 0;

        $claim = $this->prepareAiRun->claim(
            organizationId: (int) $organization->getKey(),
            request: $request,
            promptVersion: $promptVersion,
            contextPolicy: $contextPolicy,
            executionDeadlineAt: $executionDeadlineAt,
            maxToolCalls: $maxToolCalls,
            executionMode: AiExecutionMode::Async,
            initiatedByUserId: $request->initiatedByUserId ?? $actor?->getKey(),
        );
        $run = $claim['run'];
        if (! $claim['created']) {
            return $run;
        }

        try {
            $contextAssembly = $this->contextAssembler->assemble(
                organizationId: (int) $organization->getKey(),
                policy: $contextPolicy,
                inputVariables: $request->inputVariables,
                inputReferences: $request->inputReferences,
                actor: $actor,
                executionDeadlineAt: $executionDeadlineAt,
            );

            $renderedSystemPrompt = $this->promptRenderer->render($promptVersion->system_prompt, $contextAssembly->variables);
            $renderedUserPrompt = $this->promptRenderer->render($promptVersion->user_prompt_template, $contextAssembly->variables);
            AiRuntimeLimits::assertRenderedPromptWithinLimit($renderedSystemPrompt, $renderedUserPrompt, $capabilityDef);
            $renderedPromptDigest = hash('sha256', $renderedSystemPrompt."\n---\n".$renderedUserPrompt);
            $completed = $this->prepareAiRun->complete(
                run: $run,
                contextAssembly: $contextAssembly,
                renderedSystemPrompt: $renderedSystemPrompt,
                renderedUserPrompt: $renderedUserPrompt,
                renderedPromptDigest: $renderedPromptDigest,
                keyVersion: (int) Config::get('medical.key_version', 1),
            );
            if (! $completed) {
                return $run->fresh() ?? $run;
            }
        } catch (\Throwable $exception) {
            $sanitized = AiErrorSanitizer::sanitize($exception);
            $this->prepareAiRun->fail($run, $sanitized['message']);
            throw $exception;
        }

        ProcessAiRunJob::dispatch(
            organizationId: $organization->getKey(),
            runId: $run->id,
        );

        $run->status = AiRunStatus::Queued;

        return $run;
    }
}
