<?php

namespace App\Modules\Commerce\Domain\Enums;

enum GiftCertificateMovementType: string
{
    case Issued = 'issued';
    case Transferred = 'transferred';
    case Claimed = 'claimed';
    case Redeemed = 'redeemed';
    case RedemptionReversed = 'redemption_reversed';
}
