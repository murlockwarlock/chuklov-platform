<?php

namespace App\Modules\AI\Infrastructure\Tools;

use App\Modules\AI\Domain\Exceptions\AiToolLimitExceededException;
use App\Modules\AI\Domain\Models\AiRunToolCall;
use App\Modules\AI\Domain\Services\AiErrorSanitizer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchKnowledgeBaseSdkTool implements Tool
{
    private int $callIndex = 0;

    public function __construct(
        private readonly int $organizationId,
        private readonly int $runId,
        private readonly SearchKnowledgeBaseTool $domainTool,
        private readonly int $maxToolCalls = 10,
    ) {}

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
        $this->callIndex++;

        if ($this->callIndex > $this->maxToolCalls) {
            $this->persistProvenance(
                callIndex: $this->callIndex,
                arguments: $request->all(),
                status: 'failed',
                latencyMs: 0,
                sanitizedError: "Tool call limit of {$this->maxToolCalls} exceeded for this run.",
            );

            throw new AiToolLimitExceededException("Tool call limit of {$this->maxToolCalls} exceeded.");
        }

        $arguments = $request->all();
        $startTime = hrtime(true);
        $status = 'succeeded';
        $sanitizedError = null;

        try {
            $result = $this->domainTool->execute($this->organizationId, $arguments);
            $latencyMs = (int) round((hrtime(true) - $startTime) / 1e6);

            $this->persistProvenance(
                callIndex: $this->callIndex,
                arguments: $arguments,
                status: $status,
                latencyMs: $latencyMs,
                sanitizedError: null,
            );

            if (($result['count'] ?? 0) === 0) {
                return 'No relevant knowledge base records found.';
            }

            return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        } catch (\Throwable $e) {
            $latencyMs = (int) round((hrtime(true) - $startTime) / 1e6);
            $sanitized = AiErrorSanitizer::sanitize($e);
            $sanitizedError = $sanitized['message'];
            $status = 'failed';

            $this->persistProvenance(
                callIndex: $this->callIndex,
                arguments: $arguments,
                status: $status,
                latencyMs: $latencyMs,
                sanitizedError: $sanitizedError,
            );

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function persistProvenance(
        int $callIndex,
        array $arguments,
        string $status,
        int $latencyMs,
        ?string $sanitizedError,
    ): void {
        AiRunToolCall::query()->create([
            'organization_id' => $this->organizationId,
            'ai_run_id' => $this->runId,
            'call_index' => $callIndex,
            'tool_name' => $this->domainTool->getName(),
            'is_read_only' => $this->domainTool->isReadOnly(),
            'input_digest' => hash('sha256', (string) json_encode($arguments)),
            'execution_status' => $status,
            'latency_ms' => $latencyMs,
            'error_message_sanitized' => $sanitizedError,
        ]);
    }
}
