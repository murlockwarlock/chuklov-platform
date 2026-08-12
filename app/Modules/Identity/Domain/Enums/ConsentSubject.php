<?php

namespace App\Modules\Identity\Domain\Enums;

enum ConsentSubject: string
{
    case Offer = 'offer';
    case Privacy = 'privacy';
    case MedicalDisclaimer = 'medical_disclaimer';
    case Marketing = 'marketing';

    public function isRequired(): bool
    {
        return $this !== self::Marketing;
    }
}
