<?php

namespace App\Modules\Attachments\Domain\Enums;

enum AttachmentType: string
{
    case MedicalReport = 'medical_report';
    case PosturePhoto = 'posture_photo';
    case CompanionImage = 'companion_image';

    public function label(): string
    {
        return match ($this) {
            self::MedicalReport => 'Медицинское заключение',
            self::PosturePhoto => 'Фото осанки',
            self::CompanionImage => 'Изображение для AI-компаньона',
        };
    }
}
