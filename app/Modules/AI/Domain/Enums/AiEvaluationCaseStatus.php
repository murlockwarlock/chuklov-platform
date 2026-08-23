<?php

namespace App\Modules\AI\Domain\Enums;

enum AiEvaluationCaseStatus: string
{
    case Passed = 'passed';
    case ExecutionFailed = 'execution_failed';
    case AssertionFailed = 'assertion_failed';
    case SchemaFailed = 'schema_failed';
    case RagFailed = 'rag_failed';
    case JudgeFailed = 'judge_failed';
    case JudgeUnavailable = 'judge_unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Passed => 'Пройдено',
            self::ExecutionFailed => 'Ошибка выполнения',
            self::AssertionFailed => 'Не выполнено требование',
            self::SchemaFailed => 'Неверный формат ответа',
            self::RagFailed => 'Проблема с источником',
            self::JudgeFailed => 'Дополнительная оценка не пройдена',
            self::JudgeUnavailable => 'Дополнительная оценка недоступна',
        };
    }

    public function isPassed(): bool
    {
        return $this === self::Passed;
    }
}
