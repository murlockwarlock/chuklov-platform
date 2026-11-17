<?php

namespace App\Modules\Sessions\Domain\Enums;

enum MedicalSessionAttachmentPlane: string
{
    case Front = 'front';
    case Side = 'side';
    case Back = 'back';

    public function label(): string
    {
        return match ($this) {
            self::Front => 'Спереди',
            self::Side => 'Сбоку',
            self::Back => 'Сзади',
        };
    }
}
