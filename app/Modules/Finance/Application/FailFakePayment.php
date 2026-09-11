<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Contracts\PaymentGateway;
use App\Modules\Finance\Domain\Enums\PaymentGatewayEventType;
use App\Modules\Finance\Domain\Enums\PaymentGatewayStatus;
use App\Modules\Finance\Domain\Enums\ProviderVerificationStatus;
use App\Modules\Finance\Domain\Models\PaymentGatewayEvent;
use App\Modules\Finance\Domain\Models\PaymentGatewayTransaction;
use App\Modules\Finance\Domain\ValueObjects\GatewayFailureEvidence;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FailFakePayment
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(GatewayFailureEvidence $evidence): PaymentGatewayTransaction
    {
        $transaction = PaymentGatewayTransaction::query()
            ->where('organization_id', $evidence->organizationId)
            ->where('gateway', $this->gateway->name())
            ->where('provider_reference', $evidence->providerReference)
            ->first();

        if ($transaction === null) {
            throw (new ModelNotFoundException)->setModel(PaymentGatewayTransaction::class);
        }

        $verified = $this->gateway->verifyFailure($evidence);

        return DB::transaction(function () use ($verified, $evidence): PaymentGatewayTransaction {
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
                    || $event->event_type !== PaymentGatewayEventType::Failure
                    || $event->provider_reference !== $verified->providerReference
                    || $event->amount_minor !== $verified->amountMinor
                    || $event->currency->value !== $verified->currency->value
                    || $event->payload_hash !== $payloadHash
                    || $event->verification_status !== ProviderVerificationStatus::Verified
                    || $event->processed_at === null) {
                    throw ValidationException::withMessages(['gateway' => 'Событие шлюза не совпадает с исходной операцией.']);
                }

                return $transaction->refresh();
            }

            if ($transaction->status !== PaymentGatewayStatus::Pending) {
                throw ValidationException::withMessages(['gateway' => 'Неудачную оплату можно зафиксировать только для операции в ожидании.']);
            }

            $event = new PaymentGatewayEvent;
            $event->forceFill([
                'organization_id' => $evidence->organizationId,
                'gateway_transaction_id' => $transaction->getKey(),
                'gateway' => $this->gateway->name(),
                'event_type' => PaymentGatewayEventType::Failure->value,
                'provider_event_id' => $verified->providerEventId,
                'provider_reference' => $verified->providerReference,
                'verification_status' => ProviderVerificationStatus::Verified->value,
                'amount_minor' => $verified->amountMinor,
                'currency' => $verified->currency->value,
                'payload_hash' => $payloadHash,
            ])->save();
            $transaction->forceFill([
                'status' => PaymentGatewayStatus::Failed->value,
                'updated_at' => now(),
            ])->save();
            $event->forceFill(['processed_at' => now()])->save();
            $organization = $transaction->organization()->firstOrFail();
            $this->audit->handle(
                organization: $organization,
                actor: null,
                action: 'finance.gateway.failed',
                targetType: PaymentGatewayTransaction::class,
                targetId: (string) $transaction->getKey(),
                metadata: [
                    'gateway' => $this->gateway->name(),
                    'source' => 'verified_event',
                    'currency' => $verified->currency->value,
                ],
            );

            return $transaction->refresh();
        });
    }

    private function assertAmountsMatch(PaymentGatewayTransaction $transaction, int $amountMinor, string $currency): void
    {
        if ($amountMinor !== $transaction->amount_minor || $currency !== $transaction->currency->value) {
            throw ValidationException::withMessages(['gateway' => 'Сумма или валюта события не совпадает с серверной операцией.']);
        }
    }

    private function payloadHash(string $eventId, string $reference, int $amountMinor, string $currency): string
    {
        return hash('sha256', implode('|', [$eventId, $reference, $amountMinor, $currency]));
    }
}
