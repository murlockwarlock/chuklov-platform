<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Referrals\Domain\Enums\ReferralRewardFormula;
use App\Modules\Referrals\Domain\Models\ReferralRewardProgramVersion;
use Brick\Math\BigInteger;
use InvalidArgumentException;

final class ReferralRewardCalculator
{
    public function calculate(ReferralRewardProgramVersion $version, Money $settlement): Money
    {
        $formula = ReferralRewardFormula::from($version->getRawOriginal('formula'));

        if ($formula === ReferralRewardFormula::FixedAmount) {
            return Money::ofMinor(
                (string) $version->getRawOriginal('fixed_amount_minor'),
                (string) $version->getRawOriginal('fixed_currency'),
            );
        }

        $basisPoints = (int) $version->getRawOriginal('percentage_basis_points');

        if ($basisPoints < 1 || $basisPoints > 10000) {
            throw new InvalidArgumentException('The reward percentage is invalid.');
        }

        $minor = BigInteger::of((string) $settlement->minorUnits())
            ->multipliedBy((string) $basisPoints)
            ->dividedBy(10000, FinancialRoundingMode::from((string) $version->getRawOriginal('rounding_mode'))->brick());

        return Money::ofMinor($minor->toString(), $settlement->currency());
    }
}
