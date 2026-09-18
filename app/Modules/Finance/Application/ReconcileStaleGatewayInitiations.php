<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\PaymentGatewayEventStatus;
use App\Modules\Finance\Domain\Enums\PaymentGatewayEventType;
use App\Modules\Finance\Domain\Enums\PaymentGatewayStatus;
use App\Modules\Finance\Domain\Enums\ProviderVerificationStatus;
use App\Modules\Finance\Domain\Models\PaymentGatewayEvent;
use App\Modules\Finance\Domain\Models\PaymentGatewayTransaction;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ReconcileStaleGatewayInitiations
{
    public function __construct(private readonly RecordScenarioEvent $scenarioEvents) {}

    public function handle(?int $limit = null): int
    {
        $now = CarbonImmutable::now();
        $cutoff = $now->subSeconds(max(1, (int) config('payments.initiation.stale_after_seconds', 300)));
        $limit ??= max(1, (int) config('payments.initiation.batch_limit', 100));
        $transactionIds = PaymentGatewayTransaction::query()
            ->where('status', PaymentGatewayStatus::Initiating->value)
            ->where('initiated_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $reconciled = 0;

        foreach ($transactionIds as $transactionId) {
            $reconciled += DB::transaction(function () use ($transactionId, $cutoff, $now): int {
                $transaction = PaymentGatewayTransaction::query()
                    ->whereKey($transactionId)
                    ->lockForUpdate()
                    ->first();
                if ($transaction === null
                    || $transaction->status !== PaymentGatewayStatus::Initiating
                    || $transaction->initiated_at === null
                    || $transaction->initiated_at->isAfter($cutoff)) {
                    return 0;
                }

                $transaction->forceFill([
                    'status' => PaymentGatewayStatus::Unknown->value,
                    'last_error' => 'Payment initiation did not complete and requires manual reconciliation.',
                    'updated_at' => $now,
                ])->save();

                $providerEventKey = 'local:payment_initiation:'.$transaction->organization_id.':'.$transaction->getKey();
                $event = new PaymentGatewayEvent;
                $event->forceFill([
                    'organization_id' => $transaction->organization_id,
                    'gateway_transaction_id' => $transaction->getKey(),
                    'gateway' => $transaction->gateway,
                    'event_type' => PaymentGatewayEventType::Unknown->value,
                    'provider_event_id' => null,
                    'provider_event_key' => $providerEventKey,
                    'provider_reference' => $transaction->provider_reference,
                    'verification_status' => ProviderVerificationStatus::Rejected->value,
                    'processing_status' => PaymentGatewayEventStatus::ReconciliationRequired->value,
                    'amount_minor' => $transaction->amount_minor,
                    'currency' => $transaction->currency->value,
                    'payload_hash' => hash('sha256', $providerEventKey),
                    'payload' => [],
                    'reconciliation_reason' => 'payment_initiation_stale',
                    'created_at' => $now,
                ])->save();
                $this->scenarioEvents->paymentReconciliationRequired($event->refresh(), $now);

                return 1;
            });
        }

        return $reconciled;
    }
}
