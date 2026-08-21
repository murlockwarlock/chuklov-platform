<?php

namespace App\Modules\AI\Domain\Enums;

enum ProviderHealthStatus: string
{
    case Unknown = 'unknown';
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Не проверен',
            self::Healthy => 'Подключен и работает',
            self::Degraded => 'Обнаружена проблема',
            self::Unavailable => 'Недоступен',
        };
    }
}
