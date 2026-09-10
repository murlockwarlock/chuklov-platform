<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Contracts\PaymentGateway;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialEntrySource;
use App\Modules\Finance\Domain\Enums\FinancialLedgerEntryType;
use App\Modules\Finance\Domain\Enums\PaymentGatewayEventType;
use App\Modules\Finance\Domain\Enums\PaymentGatewayStatus;
use App\Modules\Finance\Domain\Enums\ProviderVerificationStatus;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Models\PaymentGatewayEvent;
use App\Modules\Finance\Domain\Models\PaymentGatewayTransaction;
use App\Modules\Finance\Domain\ValueObjects\FinancialLedgerEntryData;
use App\Modules\Finance\Domain\ValueObjects\GatewayRefundEvidence;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class RefundFakePayment
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly FinancialReconciliationContract $contract,
        private readonly ReconcileFinancialObligation $reconciliation,
        private readonly AppendFinancialLedgerEntry $ledger,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(GatewayRefundEvidence $evidence): FinancialLedgerEntry
    {
        $transaction = PaymentGatewayTransaction::query()
            ->where('organization_id', $evidence->organizationId)
            ->where('gateway', $this->gateway->name())
            ->where('provider_reference', $evidence->providerReference)
            ->first();

        if ($transaction === null) {
            throw (new ModelNotFoundException)->setModel(PaymentGatewayTransaction::class);
        }

        $verified = $this->gateway->verifyRefund($evidence);

        return DB::transaction(function () use ($verified, $evidence): FinancialLedgerEntry {
            $transaction = PaymentGatewayTransaction::query()
                ->where('organization_id', $evidence->organizationId)
                ->where('gateway', $this->gateway->name())
                ->where('provider_reference', $verified->providerReference)
                ->lockForUpdate()
                ->first();

            if ($transaction === null) {
                throw (new ModelNotFoundException)->setModel(PaymentGatewayTransaction::class);
            }

            $payloadHash = $this->payloadHash($verified->providerEventId, $verified->providerReference, $verified->amountMinor, $verified->currency->value);
            $this->assertAmountsMatch($transaction, $verified->amountMinor, $verified->currency->value);
            $event = PaymentGatewayEvent::query()
                ->where('organization_id', $evidence->organizationId)
                ->where('gateway', $this->gateway->name())
                ->where('provider_event_id', $verified->providerEventId)
                ->lockForUpdate()
                ->first();

            if ($event !== null) {
                if ($event->gateway_transaction_id !== $transaction->getKey()
                    || $event->event_type !== PaymentGatewayEventType::Refund
                    || $event->provider_reference !== $verified->providerReference
                    || $event->amount_minor !== $verified->amountMinor
                    || $event->currency->value !== $verified->currency->value
                    || $event->payload_hash !== $payloadHash
                    || $event->verification_status !== ProviderVerificationStatus::Verified
                    || $event->processed_at === null
                    || $transaction->status !== PaymentGatewayStatus::Refunded
                    || $transaction->refund_ledger_entry_id === null) {
                    throw ValidationException::withMessages(['gateway' => 'Событие возврата не совпадает с исходной операцией.']);
                }

                return $transaction->refundLedgerEntry()->firstOrFail();
            }

            if ($transaction->status !== PaymentGatewayStatus::Settled || $transaction->ledger_entry_id === null) {
                throw ValidationException::withMessages(['gateway' => 'Вернуть можно только подтверждённую оплату.']);
            }

            $obligation = FinancialObligation::query()
                ->where('organization_id', $evidence->organizationId)
                ->whereKey($transaction->obligation_id)
                ->lockForUpdate()
                ->firstOrFail();
            $original = FinancialLedgerEntry::query()
                ->where('organization_id', $evidence->organizationId)
                ->whereKey($transaction->ledger_entry_id)
                ->lockForUpdate()
                ->firstOrFail();

            try {
                $originalData = $this->refundData($transaction, $obligation, $original, $verified->amountMinor, $verified->currency->value);
            } catch (UnexpectedValueException) {
                throw ValidationException::withMessages(['gateway' => 'Исходная запись оплаты недействительна для возврата.']);
            }

            if (FinancialLedgerEntry::query()
                ->where('organization_id', $evidence->organizationId)
                ->where('corrects_ledger_entry_id', $original->getKey())
                ->exists()) {
                throw ValidationException::withMessages(['gateway' => 'Для этой оплаты уже зарегистрировано исправление или возврат.']);
            }

            $event = new PaymentGatewayEvent;
            $event->forceFill([
                'organization_id' => $evidence->organizationId,
                'gateway_transaction_id' => $transaction->getKey(),
                'gateway' => $this->gateway->name(),
                'event_type' => PaymentGatewayEventType::Refund->value,
                'provider_event_id' => $verified->providerEventId,
                'provider_reference' => $verified->providerReference,
                'verification_status' => ProviderVerificationStatus::Verified->value,
                'amount_minor' => $verified->amountMinor,
                'currency' => $verified->currency->value,
                'payload_hash' => $payloadHash,
            ])->save();
            $entry = $this->ledger->handle(
                organization: $evidence->organizationId,
                obligation: $obligation,
                data: new FinancialLedgerEntryData(
                    entryType: FinancialLedgerEntryType::Correction,
                    source: FinancialEntrySource::Crm,
                    amountMinor: -$originalData['amount_minor'],
                    currency: $originalData['currency'],
                    paymentAmountMinor: -$originalData['payment_amount_minor'],
                    paymentCurrency: $originalData['payment_currency'],
                    baseAmountMinor: -$originalData['base_amount_minor'],
                    baseCurrency: $originalData['base_currency'],
                    displayAmountMinor: -$originalData['display_amount_minor'],
                    displayCurrency: $originalData['display_currency'],
                    settlementAmountMinor: -$originalData['settlement_amount_minor'],
                    settlementCurrency: $originalData['settlement_currency'],
                    conversionSnapshot: [
                        'correction_of' => $original->getKey(),
                        'original_snapshot' => $original->conversion_snapshot,
                        'source' => 'fake_gateway_refund',
                    ],
                    paymentMethod: null,
                    occurredAt: CarbonImmutable::now(),
                    note: 'Возврат тестовой оплаты',
                    actorUserId: null,
                    providerReference: null,
                    idempotencyKey: 'fake_gateway_refund_event:'.$evidence->organizationId.':'.$verified->providerEventId,
                    correctsLedgerEntryId: $original->getKey(),
                ),
            );
            $event->forceFill(['processed_at' => now()])->save();
            $transaction->forceFill([
                'status' => PaymentGatewayStatus::Refunded->value,
                'refund_ledger_entry_id' => $entry->getKey(),
                'refunded_at' => now(),
                'updated_at' => now(),
            ])->save();
            $this->reconciliation->handle($evidence->organizationId, (int) $obligation->getKey(), true);
            $organization = $obligation->organization()->firstOrFail();
            $this->audit->handle(
                organization: $organization,
                actor: null,
                action: 'finance.gateway.refunded',
                targetType: PaymentGatewayTransaction::class,
                targetId: (string) $transaction->getKey(),
                metadata: [
                    'gateway' => $this->gateway->name(),
                    'source' => 'verified_event',
                    'currency' => $verified->currency->value,
                ],
            );

            return $entry->refresh();
        });
    }

    /** @return array{amount_minor: int, currency: CurrencyCode, payment_amount_minor: int, payment_currency: CurrencyCode, base_amount_minor: int, base_currency: CurrencyCode, display_amount_minor: int, display_currency: CurrencyCode, settlement_amount_minor: int, settlement_currency: CurrencyCode} */
    private function refundData(
        PaymentGatewayTransaction $transaction,
        FinancialObligation $obligation,
        FinancialLedgerEntry $original,
        int $amountMinor,
        string $currency,
    ): array {
        $obligationData = $this->contract->validateObligation($obligation);
        $entryType = $original->getRawOriginal('entry_type');
        $source = $original->getRawOriginal('source');

        if ($entryType !== FinancialLedgerEntryType::FakeGatewaySettlement->value
            || $source !== FinancialEntrySource::FakeGateway->value
            || $original->getRawOriginal('corrects_ledger_entry_id') !== null
            || $original->getRawOriginal('provider_reference') !== $transaction->provider_reference
            || $amountMinor !== $transaction->amount_minor
            || $currency !== $transaction->currency->value) {
            throw new UnexpectedValueException('The fake payment ledger entry cannot be refunded.');
        }

        $attributes = [
            'amount_minor' => $this->contract->money($original->getRawOriginal('amount_minor'), $this->contract->currency($original->getRawOriginal('currency')), 'Invalid ledger amount.')->minorUnits(),
            'payment_amount_minor' => $this->contract->money($original->getRawOriginal('payment_amount_minor'), $this->contract->currency($original->getRawOriginal('payment_currency')), 'Invalid ledger amount.')->minorUnits(),
            'base_amount_minor' => $this->contract->money($original->getRawOriginal('base_amount_minor'), $this->contract->currency($original->getRawOriginal('base_currency')), 'Invalid ledger amount.')->minorUnits(),
            'display_amount_minor' => $this->contract->money($original->getRawOriginal('display_amount_minor'), $this->contract->currency($original->getRawOriginal('display_currency')), 'Invalid ledger amount.')->minorUnits(),
            'settlement_amount_minor' => $this->contract->money($original->getRawOriginal('settlement_amount_minor'), $this->contract->currency($original->getRawOriginal('settlement_currency')), 'Invalid ledger amount.')->minorUnits(),
        ];
        $currencies = [
            'currency' => $this->contract->currency($original->getRawOriginal('currency')),
            'payment_currency' => $this->contract->currency($original->getRawOriginal('payment_currency')),
            'base_currency' => $this->contract->currency($original->getRawOriginal('base_currency')),
            'display_currency' => $this->contract->currency($original->getRawOriginal('display_currency')),
            'settlement_currency' => $this->contract->currency($original->getRawOriginal('settlement_currency')),
        ];
        $this->contract->validateLedgerForReconciliation($original);

        if ($attributes['amount_minor'] <= 0
            || $attributes['payment_amount_minor'] <= 0
            || $attributes['base_amount_minor'] < 0
            || $attributes['display_amount_minor'] < 0
            || $attributes['settlement_amount_minor'] <= 0
            || $currencies['settlement_currency'] !== $obligationData['currencies']['settlement_currency']
            || $currencies['base_currency'] !== $obligationData['currencies']['base_currency']
            || $currencies['display_currency'] !== $obligationData['currencies']['display_currency']) {
            throw new UnexpectedValueException('The fake payment ledger entry cannot be refunded.');
        }

        return [...$attributes, ...$currencies];
    }

    private function assertAmountsMatch(PaymentGatewayTransaction $transaction, int $amountMinor, string $currency): void
    {
        if ($amountMinor !== $transaction->amount_minor || $currency !== $transaction->currency->value) {
            throw ValidationException::withMessages(['gateway' => 'Сумма или валюта возврата не совпадает с серверной операцией.']);
        }
    }

    private function payloadHash(string $eventId, string $reference, int $amountMinor, string $currency): string
    {
        return hash('sha256', implode('|', [$eventId, $reference, $amountMinor, $currency]));
    }
}
