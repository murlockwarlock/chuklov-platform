<?php

namespace App\Modules\ClientPortal\Domain\Enums;

enum ClientOnboardingStage: string
{
    case Contacts = 'contacts';
    case Profile = 'profile';
    case Service = 'service';
    case Goals = 'goals';

    public function next(): ?self
    {
        return match ($this) {
            self::Contacts => self::Profile,
            self::Profile => self::Service,
            self::Service => self::Goals,
            self::Goals => null,
        };
    }
}
