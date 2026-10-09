<?php

namespace App\Modules\Commerce\Application;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\CommerceFulfillmentStatus;
use App\Modules\Commerce\Domain\Enums\PurchaseStatus;
use App\Modules\Commerce\Domain\Models\FulfillmentEvent;
use App\Modules\Commerce\Domain\Models\Purchase;
use App\Modules\Commerce\Domain\Models\PurchaseFulfillment;
use App\Modules\Finance\Application\ReconcileFinancialObligation;
use App\Modules\Finance\Domain\Enums\PaymentGatewayStatus;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Models\PaymentGatewayTransaction;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Tracker\Application\GrantPaidTrackerAccess;
use App\Modules\Tracker\Domain\Models\TrackerPlanVersion;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CompletePaidPurchase
{
    public function __construct(
        private readonly ReconcileFinancialObligation $reconciliation,
        private readonly GrantPaidTrackerAccess $trackerAccess,
        private readonly RecordScenarioEvent $scenarioEvents,
        private readonly IssueGiftCertificate $giftCertificates,
    ) {}

    public function handle(int $organizationId, int $transactionId): ?Purchase
    {
        return DB::transaction(function () use ($organizationId, $transactionId): ?Purchase {
            $transaction = PaymentGatewayTransaction::query()
                ->where('organization_id', $organizationId)
                ->whereKey($transactionId)
                ->where('status', PaymentGatewayStatus::Settled->value)
                ->lockForUpdate()
                ->first();
            if ($transaction === null) {
                return null;
            }

            $obligation = $transaction->obligation()->lockForUpdate()->first();
            if ($obligation === null || $obligation->purchase_id === null) {
                return null;
            }
            $reconciliation = $this->reconciliation->handle($organizationId, (int) $obligation->getKey(), true);
            if (! $reconciliation->isSettled()) {
                return null;
            }

            $purchase = Purchase::query()
                ->where('organization_id', $organizationId)
                ->whereKey($obligation->purchase_id)
                ->lockForUpdate()
                ->first();
            if ($purchase === null || $purchase->status === PurchaseStatus::Refunded) {
                return null;
            }

            if ($purchase->status !== PurchaseStatus::Paid) {
                $purchase->forceFill([
                    'status' => PurchaseStatus::Paid->value,
                    'paid_at' => now(),
                ])->save();
            }

            $this->processFulfillments($purchase, $transaction);

            return $purchase->refresh();
        });
    }

    public function handleSettledObligation(FinancialObligation $obligation, ?User $actor = null): ?Purchase
    {
        return DB::transaction(function () use ($obligation, $actor): ?Purchase {
            $lockedObligation = FinancialObligation::query()
                ->where('organization_id', $obligation->organization_id)
                ->whereKey($obligation->getKey())
                ->lockForUpdate()
                ->first();
            if ($lockedObligation === null || $lockedObligation->purchase_id === null) {
                return null;
            }

            $reconciliation = $this->reconciliation->handle(
                (int) $lockedObligation->organization_id,
                (int) $lockedObligation->getKey(),
                true,
            );
            if (! $reconciliation->isSettled()) {
                return null;
            }

            $purchase = Purchase::query()
                ->where('organization_id', $lockedObligation->organization_id)
                ->whereKey($lockedObligation->purchase_id)
                ->lockForUpdate()
                ->first();
            if ($purchase === null || $purchase->status === PurchaseStatus::Refunded) {
                return null;
            }

            if ($purchase->status !== PurchaseStatus::Paid) {
                $purchase->forceFill([
                    'status' => PurchaseStatus::Paid->value,
                    'paid_at' => now(),
                ])->save();
            }

            $this->processFulfillments($purchase, null, $actor);

            return $purchase->refresh();
        });
    }

    private function processFulfillments(
        Purchase $purchase,
        ?PaymentGatewayTransaction $transaction,
        ?User $actor = null,
    ): void {
        $organizationId = (int) $purchase->organization_id;

        foreach ($purchase->items()->where('organization_id', $organizationId)->orderBy('id')->get() as $item) {
            $fulfillment = PurchaseFulfillment::query()
                ->where('organization_id', $organizationId)
                ->where('purchase_item_id', $item->getKey())
                ->lockForUpdate()
                ->first();
            if (! $fulfillment instanceof PurchaseFulfillment
                || $fulfillment->status === CommerceFulfillmentStatus::Fulfilled) {
                continue;
            }

            if ($fulfillment->status === CommerceFulfillmentStatus::Failed
                && (int) $fulfillment->attempts >= $this->maxAttempts()) {
                continue;
            }

            if ($fulfillment->provider_type === 'gift_certificate') {
                $this->fulfillGiftCertificate($fulfillment, $transaction, $actor);

                continue;
            }

            if ($fulfillment->provider_type !== 'tracker_entitlement') {
                continue;
            }

            $snapshot = $item->getAttribute('product_snapshot');
            $versionId = is_array($snapshot) ? (int) ($snapshot['plan_version_id'] ?? 0) : 0;
            $version = TrackerPlanVersion::query()
                ->where('organization_id', $organizationId)
                ->whereKey($versionId)
                ->first();
            if (! $version instanceof TrackerPlanVersion) {
                $this->markFailed(
                    $fulfillment,
                    'The immutable tracker plan version is unavailable.',
                    'tracker_plan_version_unavailable',
                    $transaction,
                    $actor,
                );

                continue;
            }

            $from = $fulfillment->status->value;
            $fulfillment->forceFill([
                'status' => CommerceFulfillmentStatus::Processing->value,
                'attempts' => (int) $fulfillment->attempts + 1,
                'last_error' => null,
            ])->save();
            $this->event($fulfillment, $from, CommerceFulfillmentStatus::Processing, $transaction, $actor);
            try {
                $client = $purchase->client()->firstOrFail();
                $this->trackerAccess->handle($purchase->organization()->firstOrFail(), $client, $version);
            } catch (Throwable) {
                $this->markFailed(
                    $fulfillment,
                    'Tracker access could not be granted.',
                    'provider_failed',
                    $transaction,
                    $actor,
                );

                continue;
            }
            $fulfillment->forceFill([
                'status' => CommerceFulfillmentStatus::Fulfilled->value,
                'fulfilled_at' => now(),
            ])->save();
            $transition = $this->event(
                $fulfillment,
                CommerceFulfillmentStatus::Processing->value,
                CommerceFulfillmentStatus::Fulfilled,
                $transaction,
                $actor,
            );
            $this->scenarioEvents->fulfillmentCompleted($fulfillment, $transition, now()->toImmutable());
        }
    }

    private function markFailed(
        PurchaseFulfillment $fulfillment,
        string $message,
        string $reason,
        ?PaymentGatewayTransaction $transaction,
        ?User $actor = null,
    ): void {
        $from = $fulfillment->status->value;
        $fulfillment->forceFill([
            'status' => CommerceFulfillmentStatus::Failed->value,
            'attempts' => $fulfillment->status === CommerceFulfillmentStatus::Processing
                ? $fulfillment->attempts
                : (int) $fulfillment->attempts + 1,
            'last_error' => $message,
        ])->save();
        $transition = $this->event($fulfillment, $from, CommerceFulfillmentStatus::Failed, $transaction, $actor);
        $this->scenarioEvents->fulfillmentFailed($fulfillment, $transition, $reason, now()->toImmutable());
    }

    private function fulfillGiftCertificate(
        PurchaseFulfillment $fulfillment,
        ?PaymentGatewayTransaction $transaction,
        ?User $actor = null,
    ): void {
        $from = $fulfillment->status->value;
        $fulfillment->forceFill([
            'status' => CommerceFulfillmentStatus::Processing->value,
            'attempts' => (int) $fulfillment->attempts + 1,
            'last_error' => null,
        ])->save();
        $this->event($fulfillment, $from, CommerceFulfillmentStatus::Processing, $transaction, $actor);

        try {
            $this->giftCertificates->handle($fulfillment, $actor);
        } catch (Throwable) {
            $this->markFailed(
                $fulfillment,
                'Gift certificate could not be issued.',
                'gift_certificate_issue_failed',
                $transaction,
                $actor,
            );

            return;
        }

        $fulfillment->forceFill([
            'status' => CommerceFulfillmentStatus::Fulfilled->value,
            'fulfilled_at' => now(),
        ])->save();
        $transition = $this->event(
            $fulfillment,
            CommerceFulfillmentStatus::Processing->value,
            CommerceFulfillmentStatus::Fulfilled,
            $transaction,
            $actor,
        );
        $this->scenarioEvents->fulfillmentCompleted($fulfillment, $transition, now()->toImmutable());
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('payments.fulfillment.max_attempts', 5));
    }

    private function event(
        PurchaseFulfillment $fulfillment,
        string $from,
        CommerceFulfillmentStatus $to,
        ?PaymentGatewayTransaction $transaction,
        ?User $actor = null,
    ): FulfillmentEvent {
        $event = new FulfillmentEvent;
        $event->forceFill([
            'organization_id' => $fulfillment->organization_id,
            'fulfillment_id' => $fulfillment->getKey(),
            'from_status' => $from,
            'to_status' => $to->value,
            'actor_user_id' => $actor?->getKey(),
            'metadata' => [
                'source' => $transaction instanceof PaymentGatewayTransaction ? 'payment_settlement' : 'manual_settlement',
                'transaction_id' => $transaction?->getKey(),
            ],
        ])->save();

        return $event->refresh();
    }
}
