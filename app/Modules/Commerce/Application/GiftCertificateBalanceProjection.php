<?php

namespace App\Modules\Commerce\Application;

use App\Modules\Commerce\Domain\Models\GiftCertificate;
use App\Modules\Finance\Domain\ValueObjects\Money;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

final class GiftCertificateBalanceProjection
{
    public function balance(GiftCertificate $certificate, bool $lock = false): Money
    {
        $query = DB::table('gift_certificate_movements')
            ->where('organization_id', $certificate->organization_id)
            ->where('certificate_id', $certificate->getKey());
        $query = $lock ? $query->lockForUpdate() : $query;
        $movements = $query->get(['movement_type', 'amount_minor', 'currency']);
        $balance = 0;
        $issued = 0;
        $certificateCurrency = (string) $certificate->getRawOriginal('currency');

        foreach ($movements as $movement) {
            if ($movement->currency !== $certificateCurrency) {
                throw new UnexpectedValueException('The gift certificate movement currency is invalid.');
            }

            $amount = (int) $movement->amount_minor;
            $balance += match ($movement->movement_type) {
                'issued' => $this->issuedAmount($certificate, $amount, $issued),
                'redemption_reversed' => $amount,
                'redeemed' => -$amount,
                'transferred', 'claimed' => 0,
                default => throw new UnexpectedValueException('The gift certificate movement type is invalid.'),
            };
        }

        if ($issued !== 1) {
            throw new UnexpectedValueException('The gift certificate issued movement is missing or duplicated.');
        }

        if ($balance < 0) {
            throw new UnexpectedValueException('The gift certificate balance is negative.');
        }

        return Money::ofMinor($balance, $certificate->currency);
    }

    private function issuedAmount(GiftCertificate $certificate, int $amount, int &$issued): int
    {
        $issued++;
        if ($amount !== (int) $certificate->original_amount_minor) {
            throw new UnexpectedValueException('The gift certificate issued amount is invalid.');
        }

        return $amount;
    }
}
