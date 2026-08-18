<?php

namespace App\Modules\AI\Domain\Enums;

enum PromptVersionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::Active => 'Активная версия',
            self::Retired => 'В архиве',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
