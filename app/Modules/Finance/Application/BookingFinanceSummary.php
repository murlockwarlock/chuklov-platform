<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\ValueObjects\FinancialReconciliation;

final readonly class BookingFinanceSummary
{
    public function __construct(
        public FinancialObligation $obligation,
        public ?FinancialReconciliation $reconciliation,
    ) {}
}
