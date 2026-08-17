<?php

namespace App\Modules\AI\Domain\Services;

use App\Modules\AI\Domain\Registry\AiCapabilityDefinition;
use InvalidArgumentException;

final class AiRuntimeLimits
{
    public const int PLATFORM_MAX_INPUT_TOKENS = 8192;

    public const int PLATFORM_MAX_RAG_CONTEXT_TOKENS = 4096;

    public const int PLATFORM_MAX_OUTPUT_TOKENS = 8192;

    public const int PLATFORM_MAX_TOOL_CALLS = 5;

    public const int PLATFORM_MAX_PROVIDER_STEPS = 6;

    public const int PLATFORM_MAX_FAILOVER_ATTEMPTS = 3;

    public const int PLATFORM_MAX_RUNS_PER_MINUTE = 60;

    public const int PLATFORM_MAX_TIMEOUT_SECONDS = 180;

    public const int PLATFORM_MAX_TOOL_RESULT_TOKENS = 1024;

    public const int PLATFORM_MAX_RAG_CHUNKS = 20;

    public const int PLATFORM_MAX_CONTEXT_SESSIONS = 20;

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

    public static function providerSteps(int $maxToolCalls): int
    {
        return min(self::PLATFORM_MAX_PROVIDER_STEPS, max(1, $maxToolCalls + 1));
    }

    public static function worstCaseInputTokens(int $maxInputTokens, int $maxToolCalls): int
    {
        $boundedInputTokens = min(self::PLATFORM_MAX_INPUT_TOKENS, max(0, $maxInputTokens));
        $boundedToolCalls = min(self::PLATFORM_MAX_TOOL_CALLS, max(0, $maxToolCalls));
        $steps = self::providerSteps($boundedToolCalls);

        return ($boundedInputTokens * $steps) + ($boundedToolCalls * self::PLATFORM_MAX_TOOL_RESULT_TOKENS);
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
        ];

        foreach ($bounds as $key => [$minimum, $maximum]) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = (int) $values[$key];
            if ($value < $minimum || $value > $maximum) {
                throw new InvalidArgumentException("{$key} must be between {$minimum} and {$maximum}.");
            }
        }
    }
}
