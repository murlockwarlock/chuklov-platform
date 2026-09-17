<?php

namespace App\Modules\Commerce\Application;

use App\Modules\Finance\Domain\Enums\PaymentGatewayStatus;
use App\Modules\Finance\Domain\Models\PaymentGatewayTransaction;

final class ReprocessPaidPurchaseFulfillments
{
    public function __construct(private readonly CompletePaidPurchase $completion) {}

    public function handle(?int $limit = null): int
    {
        $limit ??= max(1, (int) config('payments.fulfillment.batch_limit', 100));
        $transactionIds = PaymentGatewayTransaction::query()
            ->where('status', PaymentGatewayStatus::Settled->value)
            ->whereHas('obligation', static fn ($query) => $query->whereNotNull('purchase_id'))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $completed = 0;

        foreach ($transactionIds as $transactionId) {
            $transaction = PaymentGatewayTransaction::query()->whereKey($transactionId)->first();
            if ($transaction === null) {
                continue;
            }
            if ($this->completion->handle((int) $transaction->organization_id, (int) $transaction->getKey()) !== null) {
                $completed++;
            }
        }

        return $completed;
    }
}
