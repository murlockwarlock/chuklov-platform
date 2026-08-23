<?php

namespace App\Modules\AI\Domain\Enums;

enum AiEvaluationCheckCategory: string
{
    case Execution = 'execution';
    case Assertion = 'assertion';
    case Schema = 'schema';
    case Rag = 'rag';
    case Judge = 'judge';

    public function label(): string
    {
        return match ($this) {
            self::Execution => 'Выполнение',
            self::Assertion => 'Содержимое ответа',
            self::Schema => 'Структура ответа',
            self::Rag => 'Источники и база знаний',
            self::Judge => 'Дополнительная оценка',
        };
    }
}
