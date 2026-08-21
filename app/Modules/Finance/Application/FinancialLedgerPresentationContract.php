<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\FinancialLedgerEntryType;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\ValueObjects\Money;
use UnexpectedValueException;

final class FinancialLedgerPresentationContract
{
    public function __construct(private readonly FinancialReconciliationContract $reconciliation) {}

    public function validatePaymentAmount(FinancialLedgerEntry $entry): Money
    {
        $entryType = is_string($entry->getRawOriginal('entry_type'))
            ? FinancialLedgerEntryType::tryFrom($entry->getRawOriginal('entry_type'))
            : null;

        if ($entryType === null) {
            throw new UnexpectedValueException('The ledger entry type is invalid for presentation.');
        }

        $currency = $this->reconciliation->currency($entry->getRawOriginal('payment_currency'));
        $amount = $this->reconciliation->money(
            $entry->getRawOriginal('payment_amount_minor'),
            $currency,
            'The ledger payment amount is invalid for presentation.',
        );

        $validSign = match ($entryType) {
            FinancialLedgerEntryType::ManualPayment,
            FinancialLedgerEntryType::FakeGatewaySettlement => $amount->isPositive(),
            FinancialLedgerEntryType::Correction => $amount->isNegative(),
        };

        if (! $validSign) {
            throw new UnexpectedValueException('The ledger payment amount is invalid for presentation.');
        }

        return $amount;
    }
}
