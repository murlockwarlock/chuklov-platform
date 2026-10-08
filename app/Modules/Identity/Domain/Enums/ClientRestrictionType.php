<?php

namespace App\Modules\Identity\Domain\Enums;

enum ClientRestrictionType: string
{
    case SelfBooking = 'self_booking';
    case Blacklist = 'blacklist';
}
