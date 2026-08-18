<?php

namespace App\Modules\AI\Domain\ValueObjects;

use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use InvalidArgumentException;

final readonly class AiExecutionPolicySnapshot
{
    /**
     * @param  list<string>  $allowedTools
     */
    public function __construct(
        public int $maxFailoverAttempts,
        public int $maxOutputTokens,
        public int $maxToolCalls,
        public int $attemptTimeoutSeconds,
        public array $allowedTools,
    ) {
        if ($this->maxFailoverAttempts < 1 || $this->maxFailoverAttempts > AiRuntimeLimits::PLATFORM_MAX_FAILOVER_ATTEMPTS) {
            throw new InvalidArgumentException('Execution policy failover limit is outside the platform safety bounds.');
        }

        if ($this->maxOutputTokens < 1 || $this->maxOutputTokens > AiRuntimeLimits::PLATFORM_MAX_OUTPUT_TOKENS) {
            throw new InvalidArgumentException('Execution policy output token limit is outside the platform safety bounds.');
        }

        if ($this->maxToolCalls < 0 || $this->maxToolCalls > AiRuntimeLimits::PLATFORM_MAX_TOOL_CALLS) {
            throw new InvalidArgumentException('Execution policy tool-call limit is outside the platform safety bounds.');
        }

        if ($this->attemptTimeoutSeconds < 1 || $this->attemptTimeoutSeconds > AiRuntimeLimits::PLATFORM_MAX_TIMEOUT_SECONDS) {
            throw new InvalidArgumentException('Execution policy timeout is outside the platform safety bounds.');
        }

        foreach ($this->allowedTools as $toolName) {
            if ($toolName === '') {
                throw new InvalidArgumentException('Execution policy contains an invalid tool name.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromArray(array $snapshot): self
    {
        if (($snapshot['version'] ?? null) !== 1
            || ! is_int($snapshot['max_failover_attempts'] ?? null)
            || ! is_int($snapshot['max_output_tokens'] ?? null)
            || ! is_int($snapshot['max_tool_calls'] ?? null)
            || ! is_int($snapshot['attempt_timeout_seconds'] ?? null)
            || ! is_array($snapshot['allowed_tools'] ?? null)) {
            throw new InvalidArgumentException('Immutable AI execution policy snapshot is invalid.');
        }

        $allowedTools = [];
        foreach ($snapshot['allowed_tools'] as $toolName) {
            if (! is_string($toolName)) {
                throw new InvalidArgumentException('Immutable AI execution policy snapshot contains an invalid tool name.');
            }

            $allowedTools[] = $toolName;
        }

        return new self(
            maxFailoverAttempts: $snapshot['max_failover_attempts'],
            maxOutputTokens: $snapshot['max_output_tokens'],
            maxToolCalls: $snapshot['max_tool_calls'],
            attemptTimeoutSeconds: $snapshot['attempt_timeout_seconds'],
            allowedTools: $allowedTools,
        );
    }

    /**
     * @param  list<string>  $capabilityAllowedTools
     * @param  list<string>  $promptAllowedTools
     * @param  list<string>  $disabledTools
     * @return list<string>
     */
    public static function effectiveAllowedTools(
        array $capabilityAllowedTools,
        array $promptAllowedTools,
        array $disabledTools,
        bool $ragAllowed,
    ): array {
        $allowedTools = array_values(array_diff(
            array_intersect($capabilityAllowedTools, $promptAllowedTools),
            $disabledTools,
        ));

        if (! $ragAllowed) {
            $allowedTools = array_values(array_diff($allowedTools, ['search_knowledge_base']));
        }

        return $allowedTools;
    }

    /**
     * @return array{version: int, max_failover_attempts: int, max_output_tokens: int, max_tool_calls: int, attempt_timeout_seconds: int, allowed_tools: list<string>}
     */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'max_failover_attempts' => $this->maxFailoverAttempts,
            'max_output_tokens' => $this->maxOutputTokens,
            'max_tool_calls' => $this->maxToolCalls,
            'attempt_timeout_seconds' => $this->attemptTimeoutSeconds,
            'allowed_tools' => $this->allowedTools,
        ];
    }

    public function tighten(self $current): self
    {
        return new self(
            maxFailoverAttempts: min($this->maxFailoverAttempts, $current->maxFailoverAttempts),
            maxOutputTokens: min($this->maxOutputTokens, $current->maxOutputTokens),
            maxToolCalls: min($this->maxToolCalls, $current->maxToolCalls),
            attemptTimeoutSeconds: min($this->attemptTimeoutSeconds, $current->attemptTimeoutSeconds),
            allowedTools: array_values(array_intersect($this->allowedTools, $current->allowedTools)),
        );
    }
}
