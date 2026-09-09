<?php

namespace App\Modules\Tracker\Domain\Enums;

enum TrackerEntitlementSource: string
{
    case Manual = 'manual';
    case FreeMode = 'free_mode';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Выдан сотрудником',
            self::FreeMode => 'Бесплатный режим',
        };
    }
}
