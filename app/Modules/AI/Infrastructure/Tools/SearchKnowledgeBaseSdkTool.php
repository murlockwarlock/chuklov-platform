<?php

namespace App\Modules\AI\Infrastructure\Tools;

use App\Modules\AI\Domain\Exceptions\AiRagRetrievalException;
use App\Modules\AI\Domain\Exceptions\AiToolExecutionFencedException;
use App\Modules\AI\Domain\Exceptions\AiToolLimitExceededException;
use App\Modules\AI\Domain\Models\AiRunRagReference;
use App\Modules\AI\Domain\Models\AiRunToolCall;
use App\Modules\AI\Domain\Services\AiErrorSanitizer;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use App\Modules\AI\Domain\ValueObjects\AiRunExecutionContext;
use App\Modules\Knowledge\Domain\Enums\KnowledgeAudience;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingExecutionSnapshot;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchKnowledgeBaseSdkTool implements Tool
{
    private readonly int $maxToolCalls;

    /** @var list<int> */
    private readonly array $allowedKnowledgeSourceIds;

    private readonly int $policyMaxResults;

    /**
     * @param  list<int>  $allowedKnowledgeSourceIds
     */
    public function __construct(
        private readonly AiRunExecutionContext $executionContext,
        private readonly SearchKnowledgeBaseTool $domainTool,
        int $maxToolCalls = 10,
        private readonly float $minimumSimilarity = 0.0,
        array $allowedKnowledgeSourceIds = [],
        int $policyMaxResults = AiRuntimeLimits::PLATFORM_MAX_RAG_CHUNKS,
        private readonly ?KnowledgeAudience $audience = null,
    ) {
        $this->maxToolCalls = min(AiRuntimeLimits::PLATFORM_MAX_TOOL_CALLS, max(0, $maxToolCalls));
        $this->allowedKnowledgeSourceIds = array_values(array_unique(array_map('intval', $allowedKnowledgeSourceIds)));
        $this->policyMaxResults = min(
            AiRuntimeLimits::PLATFORM_MAX_RAG_CHUNKS,
            max(1, $policyMaxResults),
        );
    }

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
                ->max(AiRuntimeLimits::PLATFORM_MAX_RAG_QUERY_CHARACTERS)
                ->required(),
            'max_results' => $schema
                ->integer()
                ->description('Максимальное количество фрагментов (1-10).'),
            'knowledge_source_ids' => $schema
                ->array()
                ->items($schema->integer())
                ->description('Необязательное сужение поиска до разрешенных источников.'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $arguments = $request->all();
        $startTime = hrtime(true);
        $toolStartedAt = CarbonImmutable::now();
        $runDeadline = CarbonImmutable::instance($this->authoritativeExecutionDeadline());
        $toolWindowDeadline = $toolStartedAt->addSeconds(AiRuntimeLimits::PLATFORM_MAX_TOOL_EXECUTION_SECONDS);
        $toolDeadline = $runDeadline->lessThan($toolWindowDeadline) ? $runDeadline : $toolWindowDeadline;
        $call = $this->claimProvenance($arguments, $toolDeadline);
        $effectiveArguments = $arguments;
        $retrievalPerformed = false;

        try {
            $effectiveArguments = $this->applyPolicy($arguments);
            $retrievalPerformed = ! $this->isEmptySourceIntersection($arguments)
                && trim((string) ($effectiveArguments['query'] ?? '')) !== '';
            $embeddingSnapshot = $retrievalPerformed ? $this->embeddingSnapshot() : null;
            $embeddingSnapshot?->assertCurrent();
            $executionTimeoutSeconds = min(
                AiRuntimeLimits::PLATFORM_MAX_TOOL_EXECUTION_SECONDS,
                AiRuntimeLimits::remainingExecutionSeconds($toolDeadline),
            );
            if ($executionTimeoutSeconds < 1) {
                throw new AiRagRetrievalException(
                    'Knowledge retrieval reached its bounded tool execution deadline.',
                    reason: 'timeout',
                );
            }
            $result = $this->isEmptySourceIntersection($arguments)
                ? ['results' => [], 'count' => 0]
                : $this->domainTool->execute(
                    organizationId: $this->executionContext->organizationId,
                    input: $effectiveArguments,
                    executionDeadlineAt: $toolDeadline,
                    executionTimeoutSeconds: $executionTimeoutSeconds,
                    embeddingSnapshot: $embeddingSnapshot,
                    audience: $this->audience,
                );
            if (! AiRuntimeLimits::deadlineIsActive($toolDeadline)) {
                throw new AiRagRetrievalException(
                    'Knowledge retrieval exceeded the bounded tool execution time.',
                    reason: 'timeout',
                );
            }
            $result = $this->filterBySourceScope($result);
            $result = $this->filterBySimilarity($result);
            $latencyMs = (int) round((hrtime(true) - $startTime) / 1e6);

            $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (! is_string($encoded) || AiRuntimeLimits::upperBoundTokenCount($encoded) > AiRuntimeLimits::PLATFORM_MAX_TOOL_RESULT_TOKENS) {
                throw new AiRagRetrievalException(
                    'Knowledge tool result exceeds the bounded tool context limit.',
                    reason: 'context_limit',
                );
            }

            if (! $this->finalizeProvenance(
                call: $call,
                status: 'succeeded',
                latencyMs: $latencyMs,
                sanitizedError: null,
                result: $result,
                retrievalQuery: $retrievalPerformed ? (string) ($effectiveArguments['query'] ?? '') : null,
                toolDeadline: $toolDeadline,
            )) {
                throw new AiToolExecutionFencedException('Worker lease was lost before tool provenance finalization.');
            }

            if (($result['count'] ?? 0) === 0) {
                return 'No relevant knowledge base records found.';
            }

            return $encoded;
        } catch (AiRagRetrievalException $e) {
            $latencyMs = (int) round((hrtime(true) - $startTime) / 1e6);
            $sanitized = AiErrorSanitizer::sanitize($e);

            if (! $this->finalizeProvenance(
                call: $call,
                status: 'failed',
                latencyMs: $latencyMs,
                sanitizedError: $sanitized['message'],
                retrievalQuery: isset($effectiveArguments['query']) ? (string) $effectiveArguments['query'] : null,
                toolDeadline: $toolDeadline,
            )) {
                throw new AiToolExecutionFencedException('Worker lease was lost before tool failure provenance finalization.');
            }

            throw $e;
        } catch (\Throwable $e) {
            $latencyMs = (int) round((hrtime(true) - $startTime) / 1e6);
            $sanitized = AiErrorSanitizer::sanitize($e);

            if (! $this->finalizeProvenance(
                call: $call,
                status: 'failed',
                latencyMs: $latencyMs,
                sanitizedError: $sanitized['message'],
                retrievalQuery: isset($effectiveArguments['query']) ? (string) $effectiveArguments['query'] : null,
                toolDeadline: $toolDeadline,
            )) {
                throw new AiToolExecutionFencedException('Worker lease was lost before tool failure provenance finalization.');
            }

            throw new AiRagRetrievalException(
                $e instanceof InvalidArgumentException
                    ? 'Knowledge retrieval configuration is invalid.'
                    : 'Knowledge retrieval failed safely.',
                reason: $e instanceof InvalidArgumentException ? 'configuration' : 'infrastructure',
                previous: $e,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function filterBySourceScope(array $result): array
    {
        if ($this->allowedKnowledgeSourceIds === [] || ! is_array($result['results'] ?? null)) {
            return $result;
        }

        $result['results'] = array_values(array_filter(
            $result['results'],
            fn (mixed $item): bool => is_array($item)
                && in_array((int) ($item['source_id'] ?? 0), $this->allowedKnowledgeSourceIds, true),
        ));
        $result['count'] = count($result['results']);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function filterBySimilarity(array $result): array
    {
        if (! is_array($result['results'] ?? null)) {
            return $result;
        }

        if ($this->minimumSimilarity > 0.0) {
            $result['results'] = array_values(array_filter(
                $result['results'],
                fn (mixed $item): bool => is_array($item)
                    && (float) ($item['similarity'] ?? 0.0) >= $this->minimumSimilarity,
            ));
        }
        $result['results'] = array_slice($result['results'], 0, $this->policyMaxResults);
        $result['count'] = count($result['results']);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function applyPolicy(array $arguments): array
    {
        $requestedSources = $this->sourceIds($arguments['knowledge_source_ids'] ?? null);
        $effectiveSources = $this->allowedKnowledgeSourceIds === []
            ? $requestedSources
            : ($requestedSources === []
                ? $this->allowedKnowledgeSourceIds
                : array_values(array_intersect($this->allowedKnowledgeSourceIds, $requestedSources)));
        $requestedLimit = array_key_exists('max_results', $arguments) ? (int) $arguments['max_results'] : $this->policyMaxResults;

        $arguments['max_results'] = min(
            $this->policyMaxResults,
            AiRuntimeLimits::PLATFORM_MAX_RAG_CHUNKS,
            max(1, $requestedLimit),
        );
        if ($effectiveSources !== []) {
            $arguments['knowledge_source_ids'] = $effectiveSources;
        }

        return $arguments;
    }

    /** @return list<int> */
    private function sourceIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $value)));
    }

    /** @param array<string, mixed> $arguments */
    private function isEmptySourceIntersection(array $arguments): bool
    {
        $requestedSources = $this->sourceIds($arguments['knowledge_source_ids'] ?? null);

        return $this->allowedKnowledgeSourceIds !== []
            && $requestedSources !== []
            && array_intersect($this->allowedKnowledgeSourceIds, $requestedSources) === [];
    }

    /** @param array<string, mixed> $arguments */
    private function claimProvenance(array $arguments, CarbonInterface $toolDeadline): AiRunToolCall
    {
        return DB::transaction(function () use ($arguments, $toolDeadline): AiRunToolCall {
            $run = DB::table('ai_runs')
                ->where('organization_id', $this->executionContext->organizationId)
                ->where('id', $this->executionContext->aiRunId)
                ->lockForUpdate()
                ->first();

            if ($run === null
                || $run->status !== 'running'
                || $run->worker_lease_token !== $this->executionContext->workerLeaseToken
                || $run->execution_deadline_at === null
                || now()->greaterThanOrEqualTo($run->execution_deadline_at)
                || ! AiRuntimeLimits::deadlineIsActive($toolDeadline)) {
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

    private function authoritativeExecutionDeadline(): CarbonInterface
    {
        $deadline = DB::table('ai_runs')
            ->where('organization_id', $this->executionContext->organizationId)
            ->where('id', $this->executionContext->aiRunId)
            ->value('execution_deadline_at');

        if ($deadline instanceof CarbonInterface) {
            return $deadline;
        }

        if (! is_string($deadline) || trim($deadline) === '') {
            throw new AiToolExecutionFencedException('AI run has no immutable execution deadline.');
        }

        return Carbon::parse($deadline);
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function finalizeProvenance(
        AiRunToolCall $call,
        string $status,
        int $latencyMs,
        ?string $sanitizedError,
        CarbonInterface $toolDeadline,
        ?array $result = null,
        ?string $retrievalQuery = null,
    ): bool {
        return (bool) DB::transaction(function () use ($call, $status, $latencyMs, $sanitizedError, $result, $retrievalQuery, $toolDeadline): bool {
            $run = DB::table('ai_runs')
                ->where('organization_id', $this->executionContext->organizationId)
                ->where('id', $this->executionContext->aiRunId)
                ->lockForUpdate()
                ->first();

            if ($run === null
                || $run->status !== 'running'
                || $run->worker_lease_token !== $this->executionContext->workerLeaseToken
                || $run->execution_deadline_at === null
                || now()->greaterThanOrEqualTo($run->execution_deadline_at)
                || ! AiRuntimeLimits::deadlineIsActive($toolDeadline)) {
                return false;
            }

            $updated = AiRunToolCall::query()
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

            if (! $updated) {
                return $updated;
            }

            if ($retrievalQuery !== null) {
                $provenance = $this->provenanceFromRaw($run->context_provenance ?? null);
                $embedding = is_array($provenance['retrieval_embedding'] ?? null)
                    ? $provenance['retrieval_embedding']
                    : [];
                if ($status === 'succeeded') {
                    $snapshot = EmbeddingExecutionSnapshot::fromArray($embedding);
                    $embedding['tool_query_count'] = (int) ($embedding['tool_query_count'] ?? 0) + 1;
                    $embedding['tool_query_characters'] = (int) ($embedding['tool_query_characters'] ?? 0) + mb_strlen($retrievalQuery);
                    $embedding['estimated_cost_minor_units'] = (int) ($embedding['estimated_cost_minor_units'] ?? 0)
                        + $snapshot->pricing->estimateCostForQuery($retrievalQuery);
                } else {
                    $embedding['requires_conservative_settlement'] = true;
                }
                $provenance['retrieval_embedding'] = $embedding;
                DB::table('ai_runs')
                    ->where('organization_id', $this->executionContext->organizationId)
                    ->where('id', $this->executionContext->aiRunId)
                    ->update(['context_provenance' => json_encode($provenance), 'updated_at' => now()]);
            }

            if ($status !== 'succeeded' || ! is_array($result)) {
                return true;
            }

            $nextReferenceIndex = (int) (AiRunRagReference::query()
                ->where('organization_id', $this->executionContext->organizationId)
                ->where('ai_run_id', $this->executionContext->aiRunId)
                ->max('reference_index') ?? 0);

            foreach ((array) ($result['results'] ?? []) as $reference) {
                if (! is_array($reference)
                    || ! isset($reference['source_id'], $reference['revision_id'], $reference['chunk_id'], $reference['chunk_index'], $reference['embedding_configuration_key'])) {
                    continue;
                }

                $nextReferenceIndex++;
                AiRunRagReference::create([
                    'organization_id' => $this->executionContext->organizationId,
                    'ai_run_id' => $this->executionContext->aiRunId,
                    'ai_run_tool_call_id' => $call->id,
                    'reference_index' => $nextReferenceIndex,
                    'knowledge_source_id' => (int) $reference['source_id'],
                    'knowledge_revision_id' => (int) $reference['revision_id'],
                    'knowledge_chunk_id' => (int) $reference['chunk_id'],
                    'chunk_index' => (int) $reference['chunk_index'],
                    'similarity_score' => (float) ($reference['similarity'] ?? 0.0),
                    'configuration_key' => (string) $reference['embedding_configuration_key'],
                    'retrieval_type' => 'tool',
                ]);
            }

            return true;
        });
    }

    private function embeddingSnapshot(): EmbeddingExecutionSnapshot
    {
        $rawProvenance = DB::table('ai_runs')
            ->where('organization_id', $this->executionContext->organizationId)
            ->where('id', $this->executionContext->aiRunId)
            ->value('context_provenance');
        $provenance = $this->provenanceFromRaw($rawProvenance);
        $embedding = $provenance['retrieval_embedding'] ?? null;

        if (! is_array($embedding)) {
            throw new AiRagRetrievalException(
                'Knowledge retrieval configuration snapshot is unavailable.',
                reason: 'configuration',
            );
        }

        return EmbeddingExecutionSnapshot::fromArray($embedding);
    }

    /** @return array<string, mixed> */
    private function provenanceFromRaw(mixed $rawProvenance): array
    {
        if (is_array($rawProvenance)) {
            return $rawProvenance;
        }

        if (! is_string($rawProvenance) || trim($rawProvenance) === '') {
            return [];
        }

        $decoded = json_decode($rawProvenance, true);

        return is_array($decoded) ? $decoded : [];
    }
}
