<?php

namespace App\Modules\AI\Domain\Enums;

enum ModelLifecycleStatus: string
{
    case Active = 'active';
    case Preview = 'preview';
    case Deprecated = 'deprecated';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Активна',
            self::Preview => 'Предварительная версия (Preview)',
            self::Deprecated => 'Устарела (Deprecated)',
        };
    }
}
