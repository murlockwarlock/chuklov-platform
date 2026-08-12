<?php

namespace App\Modules\Identity\Domain\Enums;

enum ChannelIdentityStatus: string
{
    case Unverified = 'unverified';
    case Verified = 'verified';
    case Revoked = 'revoked';
}
