<?php

namespace App\Modules\Finance\Application;

use App\Modules\Commerce\Application\CompletePaidPurchase;
use App\Modules\Finance\Domain\Enums\FinancialEntrySource;
use App\Modules\Finance\Domain\Enums\FinancialLedgerEntryType;
use App\Modules\Finance\Domain\Enums\PaymentGatewayEventStatus;
use App\Modules\Finance\Domain\Enums\PaymentGatewayEventType;
use App\Modules\Finance\Domain\Enums\PaymentGatewayStatus;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Models\PaymentGatewayEvent;
use App\Modules\Finance\Domain\Models\PaymentGatewayTransaction;
use App\Modules\Finance\Domain\ValueObjects\FinancialLedgerEntryData;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ProcessPaymentGatewayEvent
{
    public function __construct(
        private readonly ReconcileFinancialObligation $reconciliation,
        private readonly CurrencyConfigurationService $configuration,
        private readonly AppendFinancialLedgerEntry $ledger,
        private readonly RecordFinancialSettlementEvent $settlementEvents,
        private readonly RecordAuditEvent $audit,
        private readonly CompletePaidPurchase $purchaseCompletion,
    ) {}

    public function handle(int $organizationId, int $eventId): PaymentGatewayEvent
    {
        $event = DB::transaction(function () use ($organizationId, $eventId): PaymentGatewayEvent {
            $event = PaymentGatewayEvent::query()
                ->where('organization_id', $organizationId)
                ->whereKey($eventId)
                ->lockForUpdate()
                ->first();

            if ($event === null) {
                throw (new ModelNotFoundException)->setModel(PaymentGatewayEvent::class, [$eventId]);
            }

            if (in_array($event->processing_status, [
                PaymentGatewayEventStatus::Processed,
                PaymentGatewayEventStatus::Rejected,
                PaymentGatewayEventStatus::ReconciliationRequired,
            ], true)) {
                return $event;
            }

            $now = CarbonImmutable::now();
            if ($event->processing_status === PaymentGatewayEventStatus::Processing
                && $event->lease_expires_at !== null
                && $event->lease_expires_at->isFuture()) {
                return $event;
            }

            $attempt = (int) $event->attempt_count + 1;
            $event->forceFill([
                'processing_status' => PaymentGatewayEventStatus::Processing->value,
                'attempt_count' => $attempt,
                'lease_expires_at' => $now->copy()->addSeconds($this->leaseSeconds()),
                'last_error' => null,
            ])->save();

            if ($attempt > $this->maxAttempts()) {
                return $this->markReconciliationRequired($event, 'pending_link_stale', 'Payment event exceeded the bounded linking attempts.');
            }

            $transaction = PaymentGatewayTransaction::query()
                ->where('organization_id', $organizationId)
                ->where('gateway', $event->gateway)
                ->where('provider_reference', $event->provider_reference)
                ->lockForUpdate()
                ->first();

            if ($transaction === null) {
                return $this->returnToPendingLink($event, $now);
            }

            if ($event->provider_reference === null
                || $transaction->provider_reference !== $event->provider_reference
                || $transaction->gateway !== $event->gateway) {
                return $this->markReconciliationRequired($event, 'provider_reference_mismatch', 'The provider reference could not be linked to the transaction.');
            }

            $event->forceFill(['gateway_transaction_id' => $transaction->getKey()])->save();

            return match ($event->event_type) {
                PaymentGatewayEventType::Failure => $this->processFailure($event, $transaction, $now),
                PaymentGatewayEventType::Settlement => $this->processSettlement($event, $transaction, $now),
                default => $this->markReconciliationRequired($event, 'unsupported_automatic_event', 'The provider event is not eligible for automatic processing.'),
            };
        });

        if ($event->processing_status === PaymentGatewayEventStatus::Processed
            && $event->event_type === PaymentGatewayEventType::Settlement
            && $event->gateway_transaction_id !== null) {
            try {
                $this->purchaseCompletion->handle($organizationId, (int) $event->gateway_transaction_id);
            } catch (\Throwable $exception) {
                Log::warning('payment.purchase_fulfillment_failed', [
                    'organization_id' => $organizationId,
                    'gateway_transaction_id' => (int) $event->gateway_transaction_id,
                    'exception_class' => $exception::class,
                ]);
            }
        }

        return $event;
    }

    private function processFailure(
        PaymentGatewayEvent $event,
        PaymentGatewayTransaction $transaction,
        CarbonImmutable $now,
    ): PaymentGatewayEvent {
        if ($event->amount_minor === null || $event->currency === null) {
            return $this->markReconciliationRequired($event, 'missing_amount_or_currency', 'The settlement event has no supported amount and currency.');
        }

        if (! $this->amountMatches($event, $transaction)) {
            return $this->markReconciliationRequired($event, 'amount_or_currency_mismatch', 'The failed payment event does not match the transaction.');
        }

        if (in_array($transaction->status, [PaymentGatewayStatus::Settled, PaymentGatewayStatus::Refunded], true)) {
            return $this->markReconciliationRequired($event, 'invalid_failure_transition', 'A settled payment cannot transition to failed.');
        }

        $transaction->forceFill([
            'status' => PaymentGatewayStatus::Failed->value,
            'last_error' => 'The provider reported payment failure.',
            'updated_at' => $now,
        ])->save();

        $event->forceFill([
            'processing_status' => PaymentGatewayEventStatus::Processed->value,
            'processed_at' => $now,
            'next_attempt_at' => null,
            'lease_expires_at' => null,
        ])->save();

        $organization = Organization::query()->whereKey($transaction->organization_id)->firstOrFail();
        $this->audit->handle(
            organization: $organization,
            actor: null,
            action: 'finance.gateway.failed',
            targetType: PaymentGatewayTransaction::class,
            targetId: (string) $transaction->getKey(),
            metadata: [
                'gateway' => $transaction->gateway,
                'source' => 'verified_event',
                'currency' => $transaction->currency->value,
            ],
        );

        return $event->refresh();
    }

    private function processSettlement(
        PaymentGatewayEvent $event,
        PaymentGatewayTransaction $transaction,
        CarbonImmutable $now,
    ): PaymentGatewayEvent {
        if (! $this->amountMatches($event, $transaction)) {
            return $this->markReconciliationRequired($event, 'amount_or_currency_mismatch', 'The settlement event does not match the transaction.');
        }

        if (in_array($transaction->status, [PaymentGatewayStatus::Failed, PaymentGatewayStatus::Refunded], true)) {
            return $this->markReconciliationRequired($event, 'invalid_settlement_transition', 'The transaction cannot be settled from its current state.');
        }

        if ($transaction->status === PaymentGatewayStatus::Settled) {
            if ($transaction->ledger_entry_id === null) {
                return $this->markReconciliationRequired($event, 'settled_transaction_without_ledger', 'The settled transaction has no ledger entry.');
            }

            $event->forceFill([
                'processing_status' => PaymentGatewayEventStatus::Processed->value,
                'processed_at' => $now,
                'next_attempt_at' => null,
                'lease_expires_at' => null,
            ])->save();

            return $event->refresh();
        }

        $obligation = FinancialObligation::query()
            ->where('organization_id', $transaction->organization_id)
            ->whereKey($transaction->obligation_id)
            ->lockForUpdate()
            ->firstOrFail();
        $current = $this->reconciliation->handle((int) $transaction->organization_id, (int) $obligation->getKey(), true);
        $money = Money::ofMinor($event->amount_minor, $event->currency);

        if ($money->compareTo($current->outstanding) > 0) {
            return $this->markReconciliationRequired($event, 'amount_exceeds_outstanding', 'The settlement exceeds the remaining obligation.');
        }

        $settlementSnapshot = $this->configuration->convert($transaction->organization_id, $money, $obligation->settlement_currency);
        $baseSnapshot = $this->configuration->convert($transaction->organization_id, $money, $obligation->base_currency);
        $displaySnapshot = $this->configuration->convert($transaction->organization_id, $money, $obligation->display_currency);
        $idempotencyKey = 'gateway_event:'.$transaction->organization_id.':'.$event->provider_event_key;
        $entry = FinancialLedgerEntry::query()
            ->where('organization_id', $transaction->organization_id)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($entry === null) {
            $entry = $this->ledger->handle(
                organization: (int) $transaction->organization_id,
                obligation: $obligation,
                data: new FinancialLedgerEntryData(
                    entryType: FinancialLedgerEntryType::GatewaySettlement,
                    source: FinancialEntrySource::PaymentGateway,
                    amountMinor: $money->minorUnits(),
                    currency: $money->currency(),
                    paymentAmountMinor: $money->minorUnits(),
                    paymentCurrency: $money->currency(),
                    baseAmountMinor: (int) $baseSnapshot->targetAmountMinor,
                    baseCurrency: $baseSnapshot->targetCurrency,
                    displayAmountMinor: (int) $displaySnapshot->targetAmountMinor,
                    displayCurrency: $displaySnapshot->targetCurrency,
                    settlementAmountMinor: (int) $settlementSnapshot->targetAmountMinor,
                    settlementCurrency: $settlementSnapshot->targetCurrency,
                    conversionSnapshot: [
                        'base' => $baseSnapshot->toArray(),
                        'display' => $displaySnapshot->toArray(),
                        'settlement' => $settlementSnapshot->toArray(),
                    ],
                    paymentMethod: null,
                    occurredAt: $now->toImmutable(),
                    note: null,
                    actorUserId: null,
                    providerReference: $transaction->provider_reference,
                    idempotencyKey: $idempotencyKey,
                ),
            );
        } elseif (! $this->entryMatches($entry, $obligation, $transaction, $money)) {
            return $this->markReconciliationRequired($event, 'ledger_idempotency_conflict', 'A ledger entry already uses this provider event key with different data.');
        }

        $event->forceFill([
            'processing_status' => PaymentGatewayEventStatus::Processed->value,
            'processed_at' => $now,
            'next_attempt_at' => null,
            'lease_expires_at' => null,
        ])->save();
        $transaction->forceFill([
            'status' => PaymentGatewayStatus::Settled->value,
            'ledger_entry_id' => $entry->getKey(),
            'settled_at' => $now,
            'last_error' => null,
            'updated_at' => $now,
        ])->save();
        $settled = $this->reconciliation->handle((int) $transaction->organization_id, (int) $obligation->getKey(), true);
        if (! $current->isSettled() && $settled->isSettled()) {
            $this->settlementEvents->handle($obligation, $entry, $now);
        }

        $organization = Organization::query()->whereKey($transaction->organization_id)->firstOrFail();
        $this->audit->handle(
            organization: $organization,
            actor: null,
            action: 'finance.gateway.settled',
            targetType: PaymentGatewayTransaction::class,
            targetId: (string) $transaction->getKey(),
            metadata: [
                'gateway' => $transaction->gateway,
                'source' => 'verified_event',
                'currency' => $transaction->currency->value,
            ],
        );

        return $event->refresh();
    }

    private function amountMatches(PaymentGatewayEvent $event, PaymentGatewayTransaction $transaction): bool
    {
        return $event->amount_minor !== null
            && $event->currency !== null
            && $event->amount_minor === $transaction->amount_minor
            && $event->currency === $transaction->currency
            && $transaction->settlement_amount_minor === $event->amount_minor
            && $transaction->settlement_currency === $event->currency;
    }

    private function entryMatches(
        FinancialLedgerEntry $entry,
        FinancialObligation $obligation,
        PaymentGatewayTransaction $transaction,
        Money $money,
    ): bool {
        return (int) $entry->obligation_id === (int) $obligation->getKey()
            && $entry->entry_type === FinancialLedgerEntryType::GatewaySettlement
            && $entry->source === FinancialEntrySource::PaymentGateway
            && $entry->provider_reference === $transaction->provider_reference
            && $entry->payment_amount_minor === $money->minorUnits()
            && $entry->payment_currency === $money->currency;
    }

    private function returnToPendingLink(PaymentGatewayEvent $event, CarbonImmutable $now): PaymentGatewayEvent
    {
        if ((int) $event->attempt_count >= $this->maxAttempts()) {
            return $this->markReconciliationRequired($event, 'pending_link_stale', 'Payment event exceeded the bounded linking attempts.');
        }

        $event->forceFill([
            'processing_status' => PaymentGatewayEventStatus::PendingLink->value,
            'next_attempt_at' => $now->copy()->addSeconds($this->retrySeconds()),
            'lease_expires_at' => null,
            'last_error' => 'No local transaction exists for the provider reference yet.',
        ])->save();

        return $event->refresh();
    }

    private function markReconciliationRequired(PaymentGatewayEvent $event, string $reason, string $message): PaymentGatewayEvent
    {
        $event->forceFill([
            'processing_status' => PaymentGatewayEventStatus::ReconciliationRequired->value,
            'next_attempt_at' => null,
            'lease_expires_at' => null,
            'reconciliation_reason' => $reason,
            'last_error' => $message,
        ])->save();

        return $event->refresh();
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('payments.events.max_attempts', 20));
    }

    private function retrySeconds(): int
    {
        return max(1, (int) config('payments.events.retry_seconds', 60));
    }

    private function leaseSeconds(): int
    {
        return max(1, (int) config('payments.events.lease_seconds', 60));
    }
}
