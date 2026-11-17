<?php

namespace App\Modules\Attachments\Domain\Enums;

enum AttachmentType: string
{
    case MedicalReport = 'medical_report';
    case GeneralFile = 'general_file';
    case PosturePhoto = 'posture_photo';
    case AudioRecording = 'audio_recording';
    case ClientAvatar = 'client_avatar';
    case CompanionImage = 'companion_image';
    case CompanionDocument = 'companion_document';

    public function label(): string
    {
        return match ($this) {
            self::MedicalReport => 'Медицинское заключение',
            self::GeneralFile => 'Файл',
            self::PosturePhoto => 'Фото осанки',
            self::AudioRecording => 'Аудиозапись',
            self::ClientAvatar => 'Аватар клиента',
            self::CompanionImage => 'Изображение для AI-компаньона',
            self::CompanionDocument => 'Документ для AI-компаньона',
        };
    }
}
