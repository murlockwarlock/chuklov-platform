<?php

namespace App\Modules\Identity\Domain\Enums;

enum PatientAccessGrantScope: string
{
    case MedicalRecords = 'medical_records';

    public function label(): string
    {
        return match ($this) {
            self::MedicalRecords => __('Медицинские записи'),
        };
    }
}
