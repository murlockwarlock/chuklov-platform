<?php

namespace App\Modules\Scheduling\Domain\Enums;

enum PatientNotificationRecipientPolicy: string
{
    case PatientOnly = 'patient_only';
    case BookerOnly = 'booker_only';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::PatientOnly => __('Только пациенту'),
            self::BookerOnly => __('Только тому, кто записал'),
            self::Both => __('Пациенту и тому, кто записал'),
        };
    }
}
