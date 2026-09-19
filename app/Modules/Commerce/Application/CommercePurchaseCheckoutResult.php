<?php

namespace App\Modules\Commerce\Application;

use App\Modules\Commerce\Domain\Models\Purchase;
use App\Modules\Finance\Domain\Models\PaymentGatewayTransaction;

final readonly class CommercePurchaseCheckoutResult
{
    public function __construct(
        public Purchase $purchase,
        public PaymentGatewayTransaction $transaction,
    ) {}
}
