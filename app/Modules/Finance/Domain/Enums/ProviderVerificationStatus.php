<?php

namespace App\Modules\Finance\Domain\Enums;

enum ProviderVerificationStatus: string
{
    case Rejected = 'rejected';
    case Verified = 'verified';
}
