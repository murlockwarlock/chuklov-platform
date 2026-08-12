<?php

namespace App\Modules\Security\Domain\Enums;

enum CredentialStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
