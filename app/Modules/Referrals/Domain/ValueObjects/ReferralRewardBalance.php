<?php

namespace App\Modules\Referrals\Domain\ValueObjects;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\ValueObjects\Money;
use UnexpectedValueException;

final readonly class ReferralRewardBalance
{
    public function __construct(
        public CurrencyCode $currency,
        public Money $earned,
        public Money $reversed,
        public Money $pending,
        public Money $paid,
    ) {}

    public function accrued(): Money
    {
        return $this->earned->subtract($this->reversed);
    }

    public function available(): Money
    {
        $available = $this->accrued()->subtract($this->pending)->subtract($this->paid);

        if ($available->isNegative()) {
            throw new UnexpectedValueException('The referral reward balance is negative.');
        }

        return $available;
    }
}
