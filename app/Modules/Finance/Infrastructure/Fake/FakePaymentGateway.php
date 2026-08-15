<?php

namespace App\Modules\Finance\Infrastructure\Fake;

use App\Modules\Finance\Domain\Contracts\PaymentGateway;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Models\PaymentGatewayTransaction;
use App\Modules\Finance\Domain\ValueObjects\GatewayInitiationRequest;
use App\Modules\Finance\Domain\ValueObjects\GatewayInitiationResult;
use App\Modules\Finance\Domain\ValueObjects\GatewayReconciliationResult;
use App\Modules\Finance\Domain\ValueObjects\GatewaySettlementEvidence;
use App\Modules\Finance\Domain\ValueObjects\VerifiedGatewaySettlement;
use InvalidArgumentException;

final class FakePaymentGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function initiate(GatewayInitiationRequest $request): GatewayInitiationResult
    {
        if ($request->amountMinor <= 0) {
            throw new InvalidArgumentException('The fake gateway amount must be positive.');
        }

        $reference = 'fake-'.substr(hash('sha256', implode('|', [
            $request->organizationId,
            $request->obligationId,
            $request->amountMinor,
            $request->currency->value,
            $request->idempotencyKey,
        ])), 0, 32);

        return new GatewayInitiationResult($this->name(), $reference);
    }

    public function verifySettlement(GatewaySettlementEvidence $evidence): VerifiedGatewaySettlement
    {
        $expectedProof = self::proof(
            $evidence->organizationId,
            $evidence->providerEventId,
            $evidence->providerReference,
            $evidence->amountMinor,
            $evidence->currency,
        );

        if (! hash_equals($expectedProof, $evidence->proof)) {
            throw new InvalidArgumentException('The fake gateway evidence is not trusted.');
        }

        return new VerifiedGatewaySettlement(
            providerEventId: $evidence->providerEventId,
            providerReference: $evidence->providerReference,
            amountMinor: $evidence->amountMinor,
            currency: $evidence->currency,
        );
    }

    public function reconcile(string $providerReference, int $organizationId): GatewayReconciliationResult
    {
        $transaction = PaymentGatewayTransaction::query()
            ->where('organization_id', $organizationId)
            ->where('gateway', $this->name())
            ->where('provider_reference', $providerReference)
            ->first();

        if ($transaction === null) {
            return new GatewayReconciliationResult(
                providerReference: $providerReference,
                status: 'pending',
                amountMinor: 0,
                currency: CurrencyCode::RUB,
            );
        }

        return new GatewayReconciliationResult(
            providerReference: $providerReference,
            status: $transaction->status->value,
            amountMinor: $transaction->amount_minor,
            currency: $transaction->currency,
        );
    }

    public static function proof(
        int $organizationId,
        string $providerEventId,
        string $providerReference,
        int $amountMinor,
        CurrencyCode $currency,
    ): string {
        return hash('sha256', implode('|', [
            'fake-settlement',
            $organizationId,
            $providerEventId,
            $providerReference,
            $amountMinor,
            $currency->value,
        ]));
    }
}
