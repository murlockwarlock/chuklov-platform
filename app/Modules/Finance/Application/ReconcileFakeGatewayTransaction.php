<?php

namespace App\Modules\Finance\Application;

use App\Models\User;
use App\Modules\Finance\Domain\Contracts\PaymentGateway;
use App\Modules\Finance\Domain\Models\PaymentGatewayTransaction;
use App\Modules\Security\Application\RecordAuditEvent;

final class ReconcileFakeGatewayTransaction
{
    public function __construct(
        private readonly FinanceAuthorization $authorization,
        private readonly PaymentGateway $gateway,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, PaymentGatewayTransaction $transaction): string
    {
        $organization = $this->authorization->authorizeManage($actor);
        $this->authorization->assertOwned($transaction);

        if ($transaction->gateway !== $this->gateway->name()) {
            throw new \UnexpectedValueException('The selected payment gateway is not available.');
        }

        $result = $this->gateway->reconcile($transaction->provider_reference, (int) $organization->getKey());
        if ($result->amountMinor !== $transaction->amount_minor || $result->currency !== $transaction->currency) {
            throw new \UnexpectedValueException('Fake gateway reconciliation detected an amount mismatch.');
        }
        $expected = $transaction->status->value;
        $matches = ($expected === 'settled' && $result->status === 'settled')
            || ($expected !== 'settled' && $result->status === 'pending');

        if (! $matches) {
            throw new \UnexpectedValueException('Fake gateway reconciliation detected inconsistent state.');
        }

        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: 'finance.gateway.reconciled',
            targetType: PaymentGatewayTransaction::class,
            targetId: (string) $transaction->getKey(),
            metadata: [
                'gateway' => $this->gateway->name(),
                'status' => $result->status,
                'consistent' => true,
            ],
        );

        return $result->status;
    }
}
