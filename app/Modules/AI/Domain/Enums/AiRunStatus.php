<?php

namespace App\Modules\AI\Domain\Enums;

enum AiRunStatus: string
{
    case Preparing = 'preparing';
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case TimedOut = 'timed_out';
    case InvalidOutput = 'invalid_output';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Succeeded,
            self::Failed,
            self::Cancelled,
            self::TimedOut,
            self::InvalidOutput,
        ], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Preparing => 'Подготавливается',
            self::Queued => 'В очереди',
            self::Running => 'Выполняется',
            self::Succeeded => 'Завершен успешно',
            self::Failed => 'Ошибка выполнения',
            self::Cancelled => 'Отменен',
            self::TimedOut => 'Превышено время ожидания',
            self::InvalidOutput => 'Невалидный формат вывода',
        };
    }
}
