<?php

namespace App\Modules\AI\Domain\Services;

use App\Modules\AI\Domain\Enums\AiErrorCategory;
use App\Modules\AI\Domain\Exceptions\AiBudgetExceededException;
use App\Modules\AI\Domain\Exceptions\AiKillSwitchException;
use App\Modules\AI\Domain\Exceptions\AiOutputValidationException;
use App\Modules\AI\Domain\Exceptions\AiProviderUnavailableException;
use App\Modules\AI\Domain\Exceptions\AiRateLimitException;
use Throwable;

final class AiErrorSanitizer
{
    /**
     * @return array{category: AiErrorCategory, message: string}
     */
    public static function sanitize(Throwable $throwable): array
    {
        if ($throwable instanceof AiBudgetExceededException) {
            return [
                'category' => AiErrorCategory::BudgetExceeded,
                'message' => 'Daily spend budget for organization exceeded.',
            ];
        }

        if ($throwable instanceof AiKillSwitchException) {
            return [
                'category' => AiErrorCategory::SafetyKillSwitchActive,
                'message' => 'AI execution blocked by safety control or capability switch.',
            ];
        }

        if ($throwable instanceof AiRateLimitException) {
            return [
                'category' => AiErrorCategory::RateLimited,
                'message' => 'Provider rate limit exceeded. Please retry later.',
            ];
        }

        if ($throwable instanceof AiOutputValidationException) {
            return [
                'category' => AiErrorCategory::OutputSchemaValidationFailed,
                'message' => 'AI output failed JSON schema validation.',
            ];
        }

        if ($throwable instanceof AiProviderUnavailableException) {
            return [
                'category' => AiErrorCategory::ProviderUnavailable,
                'message' => 'Configured AI provider is unavailable or not configured.',
            ];
        }

        $rawMessage = $throwable->getMessage();

        if (preg_match('/timeout|timed out|deadline exceeded/i', $rawMessage) === 1) {
            return [
                'category' => AiErrorCategory::ExecutionTimedOut,
                'message' => 'AI provider execution timed out.',
            ];
        }

        if (preg_match('/rate limit|429|too many requests/i', $rawMessage) === 1) {
            return [
                'category' => AiErrorCategory::RateLimited,
                'message' => 'Provider rate limit exceeded.',
            ];
        }

        if (preg_match('/auth|unauthorized|401|invalid api key|forbidden|403/i', $rawMessage) === 1) {
            return [
                'category' => AiErrorCategory::AuthenticationFailed,
                'message' => 'Provider authentication failed. Check credentials.',
            ];
        }

        if (preg_match('/context length|maximum context|token limit/i', $rawMessage) === 1) {
            return [
                'category' => AiErrorCategory::ContextLengthExceeded,
                'message' => 'Prompt context length exceeded provider limits.',
            ];
        }

        return [
            'category' => AiErrorCategory::InternalError,
            'message' => 'An internal error occurred during AI execution.',
        ];
    }
}
