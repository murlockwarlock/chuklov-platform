<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Contracts\PaymentGateway;
use App\Modules\Finance\Domain\Enums\FinancialEntrySource;
use App\Modules\Finance\Domain\Enums\FinancialLedgerEntryType;
use App\Modules\Finance\Domain\Enums\PaymentGatewayStatus;
use App\Modules\Finance\Domain\Enums\ProviderVerificationStatus;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Models\PaymentGatewayEvent;
use App\Modules\Finance\Domain\Models\PaymentGatewayTransaction;
use App\Modules\Finance\Domain\ValueObjects\FinancialLedgerEntryData;
use App\Modules\Finance\Domain\ValueObjects\GatewaySettlementEvidence;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Referrals\Application\ObserveReferredPaidConversion;
use App\Modules\Referrals\Domain\ValueObjects\PaidConversionEvidence;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SettleFakePayment
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly ReconcileFinancialObligation $reconciliation,
        private readonly CurrencyConfigurationService $configuration,
        private readonly AppendFinancialLedgerEntry $ledger,
        private readonly RecordAuditEvent $audit,
        private readonly ObserveReferredPaidConversion $conversionObserver,
    ) {}

    public function handle(GatewaySettlementEvidence $evidence): FinancialLedgerEntry
    {
        $transaction = PaymentGatewayTransaction::query()
            ->where('gateway', $this->gateway->name())
            ->where('provider_reference', $evidence->providerReference)
            ->first();

        if ($transaction === null || (int) $transaction->organization_id !== $evidence->organizationId) {
            throw (new ModelNotFoundException)->setModel(PaymentGatewayTransaction::class);
        }

        $verified = $this->gateway->verifySettlement($evidence);

        return DB::transaction(function () use ($evidence, $verified) {
            $transaction = PaymentGatewayTransaction::query()
                ->where('organization_id', $evidence->organizationId)
                ->where('gateway', $this->gateway->name())
                ->where('provider_reference', $verified->providerReference)
                ->lockForUpdate()
                ->first();

            if ($transaction === null) {
                throw (new ModelNotFoundException)->setModel(PaymentGatewayTransaction::class);
            }

            if ($transaction->status === PaymentGatewayStatus::Settled && $transaction->ledger_entry_id !== null) {
                return $transaction->ledgerEntry()->firstOrFail();
            }

            if ($verified->amountMinor !== $transaction->amount_minor || $verified->currency !== $transaction->currency) {
                throw ValidationException::withMessages(['gateway' => 'Сумма подтверждения не совпадает с серверной суммой.']);
            }

            $event = PaymentGatewayEvent::query()
                ->where('organization_id', $evidence->organizationId)
                ->where('gateway', $this->gateway->name())
                ->where('provider_event_id', $verified->providerEventId)
                ->lockForUpdate()
                ->first();

            if ($event !== null) {
                if ($event->verification_status !== ProviderVerificationStatus::Verified || $event->processed_at === null) {
                    throw ValidationException::withMessages(['gateway' => 'Событие шлюза уже было отклонено или ещё обрабатывается.']);
                }

                return $transaction->ledgerEntry()->firstOrFail();
            }

            $current = $this->reconciliation->handle(
                $evidence->organizationId,
                (int) $transaction->obligation_id,
                true,
            );

            if ($verified->amountMinor > $current->outstanding->minorUnits()) {
                throw ValidationException::withMessages(['gateway' => 'Подтверждение превышает текущую задолженность.']);
            }

            $obligation = FinancialObligation::query()
                ->where('organization_id', $evidence->organizationId)
                ->whereKey($transaction->obligation_id)
                ->firstOrFail();
            $money = Money::ofMinor($verified->amountMinor, $verified->currency);
            $settlementSnapshot = $this->configuration->convert($evidence->organizationId, $money, $obligation->settlement_currency);
            $baseSnapshot = $this->configuration->convert($evidence->organizationId, $money, $obligation->base_currency);
            $displaySnapshot = $this->configuration->convert($evidence->organizationId, $money, $obligation->display_currency);
            $event = new PaymentGatewayEvent;
            $event->forceFill([
                'organization_id' => $evidence->organizationId,
                'gateway_transaction_id' => $transaction->getKey(),
                'gateway' => $this->gateway->name(),
                'provider_event_id' => $verified->providerEventId,
                'provider_reference' => $verified->providerReference,
                'verification_status' => ProviderVerificationStatus::Verified->value,
                'amount_minor' => $verified->amountMinor,
                'currency' => $verified->currency->value,
                'payload_hash' => hash('sha256', implode('|', [
                    $verified->providerEventId,
                    $verified->providerReference,
                    $verified->amountMinor,
                    $verified->currency->value,
                ])),
            ]);
            $event->save();
            $entry = $this->ledger->handle(
                organization: $evidence->organizationId,
                obligation: $obligation,
                data: new FinancialLedgerEntryData(
                    entryType: FinancialLedgerEntryType::FakeGatewaySettlement,
                    source: FinancialEntrySource::FakeGateway,
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
                    occurredAt: now()->toImmutable(),
                    note: null,
                    actorUserId: null,
                    providerReference: $verified->providerReference,
                    idempotencyKey: 'fake_gateway_event:'.$evidence->organizationId.':'.$verified->providerEventId,
                ),
            );
            $event->forceFill(['processed_at' => now()])->save();
            $transaction->forceFill([
                'status' => PaymentGatewayStatus::Settled->value,
                'ledger_entry_id' => $entry->getKey(),
                'settled_at' => now(),
                'updated_at' => now(),
            ])->save();
            $settled = $this->reconciliation->handle($evidence->organizationId, (int) $obligation->getKey(), true);
            $this->conversionObserver->handle(new PaidConversionEvidence(
                organizationId: $evidence->organizationId,
                clientId: (int) $obligation->client_id,
                obligationId: (int) $obligation->getKey(),
                ledgerEntryId: (int) $entry->getKey(),
                financeStatus: $settled->status->value,
                authoritativeSettled: $settled->isSettled(),
            ));
            $organization = $obligation->organization()->firstOrFail();
            $this->audit->handle(
                organization: $organization,
                actor: null,
                action: 'finance.gateway.settled',
                targetType: PaymentGatewayTransaction::class,
                targetId: (string) $transaction->getKey(),
                metadata: [
                    'gateway' => $this->gateway->name(),
                    'source' => 'verified_event',
                    'currency' => $verified->currency->value,
                ],
            );

            return $entry;
        });
    }
}
