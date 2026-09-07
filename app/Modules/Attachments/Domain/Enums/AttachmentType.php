<?php

namespace App\Modules\Attachments\Domain\Enums;

enum AttachmentType: string
{
    case MedicalReport = 'medical_report';
    case PosturePhoto = 'posture_photo';
    case CompanionImage = 'companion_image';
    case CompanionDocument = 'companion_document';

    public function label(): string
    {
        return match ($this) {
            self::MedicalReport => 'Медицинское заключение',
            self::PosturePhoto => 'Фото осанки',
            self::CompanionImage => 'Изображение для AI-компаньона',
            self::CompanionDocument => 'Документ для AI-компаньона',
        };
    }
}
