<?php

namespace App\Modules\AI\Domain\Enums;

enum AiExecutionMode: string
{
    case Sync = 'sync';
    case Async = 'async';
    case Playground = 'playground';
    case Evaluation = 'evaluation';

    public function label(): string
    {
        return match ($this) {
            self::Sync => 'Синхронный',
            self::Async => 'Асинхронный (Очередь)',
            self::Playground => 'Песочница',
            self::Evaluation => 'Тестирование',
        };
    }
}
