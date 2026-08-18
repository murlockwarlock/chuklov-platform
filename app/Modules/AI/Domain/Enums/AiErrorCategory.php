<?php

namespace App\Modules\AI\Domain\Enums;

enum AiErrorCategory: string
{
    case ProviderUnavailable = 'provider_unavailable';
    case RateLimited = 'rate_limited';
    case BudgetExceeded = 'budget_exceeded';
    case AuthenticationFailed = 'authentication_failed';
    case InvalidPrompt = 'invalid_prompt';
    case OutputSchemaValidationFailed = 'output_schema_validation_failed';
    case ContextLengthExceeded = 'context_length_exceeded';
    case ExecutionTimedOut = 'execution_timed_out';
    case ToolExecutionFailed = 'tool_execution_failed';
    case SafetyKillSwitchActive = 'safety_kill_switch_active';
    case InternalError = 'internal_error';

    public function label(): string
    {
        return match ($this) {
            self::ProviderUnavailable => 'Провайдер недоступен',
            self::RateLimited => 'Превышен лимит частоты запросов (Rate Limit)',
            self::BudgetExceeded => 'Превышен дневной бюджет организации',
            self::AuthenticationFailed => 'Ошибка аутентификации в API провайдера',
            self::InvalidPrompt => 'Некорректный шаблон промпта или параметры',
            self::OutputSchemaValidationFailed => 'Ответ модели не соответствует JSON-схеме',
            self::ContextLengthExceeded => 'Превышена максимальная длина контекста',
            self::ExecutionTimedOut => 'Превышено время ожидания ответа провайдера',
            self::ToolExecutionFailed => 'Ошибка при выполнении вызова инструмента',
            self::SafetyKillSwitchActive => 'AI функционал отключен политикой безопасности (Kill-Switch)',
            self::InternalError => 'Внутренняя системная ошибка',
        };
    }
}
