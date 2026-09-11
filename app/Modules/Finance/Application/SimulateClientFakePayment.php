<?php

namespace App\Modules\Finance\Application;

use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Finance\Domain\Contracts\PaymentGateway;
use App\Modules\Finance\Domain\Models\PaymentGatewayTransaction;
use App\Modules\Finance\Domain\ValueObjects\GatewayFailureEvidence;
use App\Modules\Finance\Domain\ValueObjects\GatewayRefundEvidence;
use App\Modules\Finance\Domain\ValueObjects\GatewayRefundRequest;
use App\Modules\Finance\Domain\ValueObjects\GatewaySettlementEvidence;
use App\Modules\Finance\Infrastructure\Fake\FakePaymentGateway;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

final class SimulateClientFakePayment
{
    public function __construct(
        private readonly ClientPortalContext $clientContext,
        private readonly OrganizationContext $organizationContext,
        private readonly PaymentGateway $gateway,
        private readonly SettleFakePayment $settle,
        private readonly FailFakePayment $fail,
        private readonly RefundFakePayment $refund,
    ) {}

    public function handle(int $transactionId, string $outcome): PaymentGatewayTransaction
    {
        if (app()->environment('production') || ! config('payments.fake_enabled', false) || ! $this->gateway instanceof FakePaymentGateway) {
            throw ValidationException::withMessages(['gateway' => 'Тестовый шлюз отключён в этом окружении.']);
        }

        if (! in_array($outcome, ['success', 'fail', 'refund'], true)) {
            throw ValidationException::withMessages(['outcome' => 'Сценарий тестовой оплаты указан неверно.']);
        }

        $client = $this->clientContext->client();
        $organization = $this->organizationContext->organization();
        $transaction = PaymentGatewayTransaction::query()
            ->where('organization_id', $organization->getKey())
            ->where('gateway', $this->gateway->name())
            ->whereKey($transactionId)
            ->whereHas('obligation', function ($query) use ($organization, $client): void {
                $query
                    ->where('organization_id', $organization->getKey())
                    ->where('client_id', $client->getKey());
            })
            ->first();

        if ($transaction === null) {
            throw (new ModelNotFoundException)->setModel(PaymentGatewayTransaction::class, [$transactionId]);
        }

        $eventId = 'client-demo-'.$outcome.'-'.$transaction->getKey();

        if ($outcome === 'success') {
            $this->settle->handle(new GatewaySettlementEvidence(
                organizationId: (int) $organization->getKey(),
                providerEventId: $eventId,
                providerReference: $transaction->provider_reference,
                amountMinor: $transaction->amount_minor,
                currency: $transaction->currency,
                proof: FakePaymentGateway::proof(
                    (int) $organization->getKey(),
                    $eventId,
                    $transaction->provider_reference,
                    $transaction->amount_minor,
                    $transaction->currency,
                ),
            ));
        } elseif ($outcome === 'fail') {
            $this->fail->handle(new GatewayFailureEvidence(
                organizationId: (int) $organization->getKey(),
                providerEventId: $eventId,
                providerReference: $transaction->provider_reference,
                amountMinor: $transaction->amount_minor,
                currency: $transaction->currency,
                proof: FakePaymentGateway::failureProof(
                    (int) $organization->getKey(),
                    $eventId,
                    $transaction->provider_reference,
                    $transaction->amount_minor,
                    $transaction->currency,
                ),
            ));
        } else {
            $result = $this->gateway->refund(new GatewayRefundRequest(
                organizationId: (int) $organization->getKey(),
                providerReference: $transaction->provider_reference,
                amountMinor: $transaction->amount_minor,
                currency: $transaction->currency,
                idempotencyKey: 'client-demo-refund-'.$transaction->getKey(),
            ));
            $this->refund->handle(new GatewayRefundEvidence(
                organizationId: (int) $organization->getKey(),
                providerEventId: $result->providerEventId,
                providerReference: $result->providerReference,
                amountMinor: $result->amountMinor,
                currency: $result->currency,
                proof: FakePaymentGateway::refundProof(
                    (int) $organization->getKey(),
                    $result->providerEventId,
                    $result->providerReference,
                    $result->amountMinor,
                    $result->currency,
                ),
            ));
        }

        return $transaction->refresh();
    }
}
