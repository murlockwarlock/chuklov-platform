<?php

namespace App\Modules\Sessions\Domain\Enums;

enum MedicalSessionAttachmentGallery: string
{
    case General = 'general';
    case Before = 'before';
    case After = 'after';

    public function label(): string
    {
        return match ($this) {
            self::General => 'Общие файлы',
            self::Before => 'До',
            self::After => 'После',
        };
    }
}
