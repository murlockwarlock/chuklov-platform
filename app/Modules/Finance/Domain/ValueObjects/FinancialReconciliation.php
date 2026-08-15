<?php

namespace App\Modules\Finance\Domain\ValueObjects;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialStatus;

final readonly class FinancialReconciliation
{
    public function __construct(
        public Money $obligation,
        public Money $applied,
        public Money $outstanding,
        public FinancialStatus $status,
        public Money $baseApplied,
        public Money $baseOutstanding,
        public Money $displayApplied,
        public Money $displayOutstanding,
    ) {}

    public function isSettled(): bool
    {
        return $this->status === FinancialStatus::Settled;
    }

    public function currency(): CurrencyCode
    {
        return $this->obligation->currency();
    }
}
