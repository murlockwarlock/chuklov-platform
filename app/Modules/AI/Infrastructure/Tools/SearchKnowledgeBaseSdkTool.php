<?php

namespace App\Modules\AI\Infrastructure\Tools;

use App\Modules\AI\Domain\Exceptions\AiRagRetrievalException;
use App\Modules\AI\Domain\Exceptions\AiToolExecutionFencedException;
use App\Modules\AI\Domain\Exceptions\AiToolLimitExceededException;
use App\Modules\AI\Domain\Models\AiRunToolCall;
use App\Modules\AI\Domain\Services\AiErrorSanitizer;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use App\Modules\AI\Domain\ValueObjects\AiRunExecutionContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchKnowledgeBaseSdkTool implements Tool
{
    public function __construct(
        private readonly AiRunExecutionContext $executionContext,
        private readonly SearchKnowledgeBaseTool $domainTool,
        int $maxToolCalls = 10,
        private readonly float $minimumSimilarity = 0.0,
    ) {
        $this->maxToolCalls = min(AiRuntimeLimits::PLATFORM_MAX_TOOL_CALLS, max(0, $maxToolCalls));
    }

    private readonly int $maxToolCalls;

    public function description(): Stringable|string
    {
        return $this->domainTool->getDescription();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema
                ->string()
                ->description('Поисковый запрос по материалам базы знаний.')
                ->required(),
            'max_results' => $schema
                ->integer()
                ->description('Максимальное количество фрагментов (1-10).'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $arguments = $request->all();
        $call = $this->claimProvenance($arguments);
        $startTime = hrtime(true);

        try {
            $result = $this->domainTool->execute($this->executionContext->organizationId, $arguments);
            $result = $this->filterBySimilarity($result);
            $latencyMs = (int) round((hrtime(true) - $startTime) / 1e6);

            $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (! is_string($encoded) || AiRuntimeLimits::upperBoundTokenCount($encoded) > AiRuntimeLimits::PLATFORM_MAX_TOOL_RESULT_TOKENS) {
                throw new AiRagRetrievalException(
                    'Knowledge tool result exceeds the bounded tool context limit.',
                    reason: 'context_limit',
                );
            }

            if (! $this->finalizeProvenance($call, 'succeeded', $latencyMs, null)) {
                throw new AiToolExecutionFencedException('Worker lease was lost before tool provenance finalization.');
            }

            if (($result['count'] ?? 0) === 0) {
                return 'No relevant knowledge base records found.';
            }

            return $encoded;
        } catch (AiRagRetrievalException $e) {
            $latencyMs = (int) round((hrtime(true) - $startTime) / 1e6);
            $sanitized = AiErrorSanitizer::sanitize($e);

            if (! $this->finalizeProvenance($call, 'failed', $latencyMs, $sanitized['message'])) {
                throw new AiToolExecutionFencedException('Worker lease was lost before tool failure provenance finalization.');
            }

            throw $e;
        } catch (\Throwable $e) {
            $latencyMs = (int) round((hrtime(true) - $startTime) / 1e6);
            $sanitized = AiErrorSanitizer::sanitize($e);

            if (! $this->finalizeProvenance($call, 'failed', $latencyMs, $sanitized['message'])) {
                throw new AiToolExecutionFencedException('Worker lease was lost before tool failure provenance finalization.');
            }

            throw new AiRagRetrievalException(
                'Knowledge retrieval failed safely.',
                reason: 'infrastructure',
                previous: $e,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function filterBySimilarity(array $result): array
    {
        if ($this->minimumSimilarity <= 0.0 || ! is_array($result['results'] ?? null)) {
            return $result;
        }

        $result['results'] = array_values(array_filter(
            $result['results'],
            fn (mixed $item): bool => is_array($item)
                && (float) ($item['similarity'] ?? 0.0) >= $this->minimumSimilarity,
        ));
        $result['count'] = count($result['results']);

        return $result;
    }

    /** @param array<string, mixed> $arguments */
    private function claimProvenance(array $arguments): AiRunToolCall
    {
        return DB::transaction(function () use ($arguments): AiRunToolCall {
            $run = DB::table('ai_runs')
                ->where('organization_id', $this->executionContext->organizationId)
                ->where('id', $this->executionContext->aiRunId)
                ->lockForUpdate()
                ->first();

            if ($run === null
                || $run->status !== 'running'
                || $run->worker_lease_token !== $this->executionContext->workerLeaseToken) {
                throw new AiToolExecutionFencedException('Worker lease is no longer valid for tool execution.');
            }

            $lastCallIndex = (int) (AiRunToolCall::query()
                ->where('organization_id', $this->executionContext->organizationId)
                ->where('ai_run_id', $this->executionContext->aiRunId)
                ->max('call_index') ?? 0);

            if ($lastCallIndex >= $this->maxToolCalls) {
                throw new AiToolLimitExceededException("Tool call limit of {$this->maxToolCalls} exceeded.");
            }

            return AiRunToolCall::query()->create([
                'organization_id' => $this->executionContext->organizationId,
                'ai_run_id' => $this->executionContext->aiRunId,
                'worker_lease_token' => $this->executionContext->workerLeaseToken,
                'call_index' => $lastCallIndex + 1,
                'tool_name' => $this->domainTool->getName(),
                'is_read_only' => $this->domainTool->isReadOnly(),
                'input_digest' => hash('sha256', (string) json_encode($arguments)),
                'execution_status' => 'running',
            ]);
        });
    }

    private function finalizeProvenance(
        AiRunToolCall $call,
        string $status,
        int $latencyMs,
        ?string $sanitizedError,
    ): bool {
        return (bool) DB::transaction(function () use ($call, $status, $latencyMs, $sanitizedError): bool {
            $run = DB::table('ai_runs')
                ->where('organization_id', $this->executionContext->organizationId)
                ->where('id', $this->executionContext->aiRunId)
                ->lockForUpdate()
                ->first();

            if ($run === null
                || $run->status !== 'running'
                || $run->worker_lease_token !== $this->executionContext->workerLeaseToken) {
                return false;
            }

            return AiRunToolCall::query()
                ->where('organization_id', $this->executionContext->organizationId)
                ->where('id', $call->id)
                ->where('worker_lease_token', $this->executionContext->workerLeaseToken)
                ->where('execution_status', 'running')
                ->update([
                    'execution_status' => $status,
                    'latency_ms' => $latencyMs,
                    'error_sanitized' => $sanitizedError,
                    'updated_at' => now(),
                ]) === 1;
        });
    }
}
