<?php

namespace App\Modules\Sessions\Domain\Enums;

enum MedicalSessionStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::Completed => 'Завершён',
        };
    }
}
