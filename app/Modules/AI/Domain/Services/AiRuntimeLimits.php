<?php

namespace App\Modules\AI\Domain\Services;

use App\Modules\AI\Domain\Registry\AiCapabilityDefinition;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class AiRuntimeLimits
{
    public const int PLATFORM_MAX_INPUT_TOKENS = 8192;

    public const int PLATFORM_MAX_RAG_CONTEXT_TOKENS = 4096;

    public const int PLATFORM_MAX_OUTPUT_TOKENS = 8192;

    public const int PLATFORM_MAX_TOOL_CALLS = 5;

    public const int PLATFORM_MAX_PROVIDER_STEPS = 6;

    public const int PLATFORM_MAX_FAILOVER_ATTEMPTS = 3;

    public const int PLATFORM_MAX_MODEL_CONFIGURATION_SCAN = 100;

    public const int PLATFORM_MAX_RUNS_PER_MINUTE = 60;

    public const int PLATFORM_MAX_TIMEOUT_SECONDS = 120;

    public const int PLATFORM_MAX_TOOL_EXECUTION_SECONDS = 30;

    public const int PLATFORM_EXECUTION_MARGIN_SECONDS = 30;

    public const int PLATFORM_LEASE_GRACE_SECONDS = 30;

    public const int PLATFORM_MAX_TOOL_SCHEMA_TOKENS = 1024;

    public const int PLATFORM_MAX_TOOL_RESULT_TOKENS = 1024;

    public const int PLATFORM_MAX_RAG_CHUNKS = 20;

    public const int PLATFORM_MAX_EVALUATION_CASES = 100;

    public const int PLATFORM_MAX_RAG_QUERY_CHARACTERS = 4000;

    public const int PLATFORM_MAX_CONTEXT_SESSIONS = 20;

    public const int PLATFORM_DEFAULT_MAX_DAILY_SPEND_MINOR_UNITS = 5000;

    public const int PLATFORM_QUEUE_JOB_TIMEOUT_SECONDS = 2640;

    public const int PLATFORM_HORIZON_TIMEOUT_SECONDS = 2670;

    public const int PLATFORM_QUEUE_RETRY_AFTER_SECONDS = 2700;

    public static function wholeRunSeconds(): int
    {
        return (self::PLATFORM_MAX_FAILOVER_ATTEMPTS * self::providerAttemptSeconds(
            self::PLATFORM_MAX_PROVIDER_STEPS,
            self::PLATFORM_MAX_TIMEOUT_SECONDS,
            self::PLATFORM_MAX_TOOL_CALLS,
        ))
            + self::PLATFORM_EXECUTION_MARGIN_SECONDS;
    }

    public static function providerAttemptSeconds(int $providerSteps, int $providerStepTimeout, int $toolCalls): int
    {
        return (min(self::PLATFORM_MAX_PROVIDER_STEPS, max(1, $providerSteps)) * min(self::PLATFORM_MAX_TIMEOUT_SECONDS, max(1, $providerStepTimeout)))
            + (min(self::PLATFORM_MAX_TOOL_CALLS, max(0, $toolCalls)) * self::PLATFORM_MAX_TOOL_EXECUTION_SECONDS);
    }

    public static function estimateTokens(string $value): int
    {
        if ($value === '') {
            return 0;
        }

        return (int) ceil(mb_strlen($value) / 4);
    }

    public static function upperBoundTokenCount(string $value): int
    {
        return strlen($value);
    }

    /** @param array<string, mixed> $value */
    public static function estimateArrayTokens(array $value): int
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return self::estimateTokens(is_string($encoded) ? $encoded : '');
    }

    public static function assertRenderedPromptWithinLimit(
        string $systemPrompt,
        string $userPrompt,
        AiCapabilityDefinition $capability,
    ): void {
        $inputTokens = self::upperBoundTokenCount($systemPrompt."\n".$userPrompt);
        $maxInputTokens = min($capability->maxInputTokens, self::PLATFORM_MAX_INPUT_TOKENS);

        if ($inputTokens > $maxInputTokens) {
            throw new InvalidArgumentException("Rendered prompt exceeds the bounded input limit of {$maxInputTokens} tokens.");
        }
    }

    public static function effectiveMaxOutputTokens(
        AiCapabilityDefinition $capability,
        ?int $requestedMaxTokens,
        ?int $organizationMaxTokens,
    ): int {
        return max(1, min(
            $capability->defaultMaxTokens,
            $capability->maxOutputTokens,
            self::PLATFORM_MAX_OUTPUT_TOKENS,
            $requestedMaxTokens ?? PHP_INT_MAX,
            $organizationMaxTokens ?? PHP_INT_MAX,
        ));
    }

    public static function effectiveMaxToolCalls(
        AiCapabilityDefinition $capability,
        ?int $organizationMaxToolCalls,
    ): int {
        return max(0, min(
            $capability->maxToolCalls,
            self::PLATFORM_MAX_TOOL_CALLS,
            $organizationMaxToolCalls ?? PHP_INT_MAX,
        ));
    }

    public static function effectiveMaxFailoverAttempts(?int $organizationMaxAttempts): int
    {
        return max(1, min(self::PLATFORM_MAX_FAILOVER_ATTEMPTS, $organizationMaxAttempts ?? self::PLATFORM_MAX_FAILOVER_ATTEMPTS));
    }

    public static function effectiveRunsPerMinute(?int $organizationLimit): int
    {
        return max(1, min(self::PLATFORM_MAX_RUNS_PER_MINUTE, $organizationLimit ?? self::PLATFORM_MAX_RUNS_PER_MINUTE));
    }

    public static function effectiveTimeout(
        int $requestedTimeout,
        int $capabilityMaxTimeout,
        ?int $organizationTimeout,
    ): int {
        return max(1, min(
            $requestedTimeout,
            $capabilityMaxTimeout,
            self::PLATFORM_MAX_TIMEOUT_SECONDS,
            $organizationTimeout ?? self::PLATFORM_MAX_TIMEOUT_SECONDS,
        ));
    }

    public static function remainingExecutionSeconds(CarbonInterface $executionDeadlineAt): int
    {
        $remainingMicroseconds = $executionDeadlineAt->getPreciseTimestamp(6) - now()->getPreciseTimestamp(6);

        return max(0, (int) floor($remainingMicroseconds / 1_000_000));
    }

    public static function deadlineIsActive(CarbonInterface $executionDeadlineAt): bool
    {
        return $executionDeadlineAt->getPreciseTimestamp(6) > now()->getPreciseTimestamp(6);
    }

    /** @param array<string, mixed> $inputVariables */
    public static function ragQuery(array $inputVariables): string
    {
        return trim((string) ($inputVariables['query'] ?? $inputVariables['question'] ?? $inputVariables['complaint'] ?? ''));
    }

    public static function providerSteps(int $maxToolCalls): int
    {
        return min(self::PLATFORM_MAX_PROVIDER_STEPS, max(1, $maxToolCalls + 1));
    }

    /**
     * @return array{input_tokens: int, output_tokens: int, cache_read_input_tokens: int, cache_write_input_tokens: int, reasoning_tokens: int, provider_requests: int, total_tokens: int}
     */
    public static function worstCaseProviderExposure(
        int $maxInputTokens,
        int $maxOutputTokens,
        int $maxToolCalls,
        ?int $maxProviderSteps = null,
        int $maxRagContextTokens = self::PLATFORM_MAX_RAG_CONTEXT_TOKENS,
        ?int $toolSchemaTokens = null,
    ): array {
        $boundedInputTokens = min(self::PLATFORM_MAX_INPUT_TOKENS, max(0, $maxInputTokens));
        $boundedRagContextTokens = min(self::PLATFORM_MAX_RAG_CONTEXT_TOKENS, max(0, $maxRagContextTokens));
        $boundedOutputTokens = min(self::PLATFORM_MAX_OUTPUT_TOKENS, max(0, $maxOutputTokens));
        $boundedToolCalls = min(self::PLATFORM_MAX_TOOL_CALLS, max(0, $maxToolCalls));
        $steps = min(
            self::PLATFORM_MAX_PROVIDER_STEPS,
            max(1, $maxProviderSteps ?? self::providerSteps($boundedToolCalls)),
        );
        $boundedToolSchemaTokens = min(
            self::PLATFORM_MAX_TOOL_SCHEMA_TOKENS,
            max(0, $toolSchemaTokens ?? ($boundedToolCalls > 0 ? self::PLATFORM_MAX_TOOL_SCHEMA_TOKENS : 0)),
        );

        $initialInputTokens = $boundedInputTokens + $boundedRagContextTokens;
        $inputTokens = ($initialInputTokens * $steps)
            + (int) ($boundedOutputTokens * $steps * ($steps - 1) / 2)
            + ($boundedToolCalls * self::PLATFORM_MAX_TOOL_RESULT_TOKENS * $steps)
            + ($boundedToolSchemaTokens * $steps);
        $outputTokens = $boundedOutputTokens * $steps;
        $retrievalSafeInputTokens = $inputTokens + $outputTokens;
        $reasoningTokens = $outputTokens;
        $cacheReadInputTokens = $retrievalSafeInputTokens;
        $cacheWriteInputTokens = $retrievalSafeInputTokens;
        $providerRequests = $steps;

        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cache_read_input_tokens' => $cacheReadInputTokens,
            'cache_write_input_tokens' => $cacheWriteInputTokens,
            'reasoning_tokens' => $reasoningTokens,
            'provider_requests' => $providerRequests,
            'total_tokens' => $inputTokens + $outputTokens,
        ];
    }

    public static function dailySpendCeiling(): int
    {
        return max(1, (int) config(
            'ai.platform.max_daily_spend_minor_units',
            self::PLATFORM_DEFAULT_MAX_DAILY_SPEND_MINOR_UNITS,
        ));
    }

    public static function effectiveDailySpendLimit(?int $organizationLimit): int
    {
        return min(
            self::dailySpendCeiling(),
            max(1, $organizationLimit ?? self::dailySpendCeiling()),
        );
    }

    /** @param array<string, mixed> $values */
    public static function validateOrganizationValues(array $values): void
    {
        $bounds = [
            'max_tokens_per_run' => [1, self::PLATFORM_MAX_OUTPUT_TOKENS],
            'max_runs_per_minute' => [1, self::PLATFORM_MAX_RUNS_PER_MINUTE],
            'max_tool_calls_per_run' => [0, self::PLATFORM_MAX_TOOL_CALLS],
            'default_timeout_seconds' => [1, self::PLATFORM_MAX_TIMEOUT_SECONDS],
            'max_failover_attempts' => [1, self::PLATFORM_MAX_FAILOVER_ATTEMPTS],
            'max_daily_spend_minor_units' => [1, self::dailySpendCeiling()],
        ];

        foreach ($bounds as $key => [$minimum, $maximum]) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = self::integerValue($values[$key], $key);
            if ($value < $minimum || $value > $maximum) {
                throw new InvalidArgumentException("{$key} must be between {$minimum} and {$maximum}.");
            }
        }
    }

    private static function integerValue(mixed $value, string $key): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException("{$key} must be an integer.");
    }
}
