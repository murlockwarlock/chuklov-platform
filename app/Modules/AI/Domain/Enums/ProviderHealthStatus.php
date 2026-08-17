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
            self::Unknown => 'Не проверен (Unknown)',
            self::Healthy => 'Доступен (Healthy)',
            self::Degraded => 'Деградирован (Degraded)',
            self::Unavailable => 'Недоступен (Unavailable)',
        };
    }
}
