<?php

namespace App\Modules\AI\Domain\Enums;

enum ProviderHealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Доступен (Healthy)',
            self::Degraded => 'Деградирован (Degraded)',
            self::Unavailable => 'Недоступен (Unavailable)',
        };
    }
}
