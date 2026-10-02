<?php

namespace App\Modules\ClientCompanion\Domain\Enums;

enum RequestCompanionHandoffResult: string
{
    case Created = 'created';
    case AlreadyRequested = 'already_requested';
    case Unavailable = 'unavailable';
}
