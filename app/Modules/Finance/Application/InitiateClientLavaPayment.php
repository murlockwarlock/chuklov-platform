<?php

namespace App\Modules\Finance\Application;

use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Models\PaymentGatewayTransaction;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

final class InitiateClientLavaPayment
{
    public function __construct(
        private readonly ClientPortalContext $clientContext,
        private readonly OrganizationContext $organizationContext,
        private readonly ResolvePaymentProviderOfferMapping $mappings,
        private readonly ResolveLavaPaymentSellable $sellable,
        private readonly CreateGatewayPaymentAttempt $createAttempt,
    ) {}

    public function handle(
        int $obligationId,
        string $idempotencyKey,
        string $successfulReturnUrl,
        string $failureReturnUrl,
        string $cancelReturnUrl,
    ): PaymentGatewayTransaction {
        $client = $this->clientContext->client();
        $organization = $this->organizationContext->organization();
        $obligation = FinancialObligation::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->whereKey($obligationId)
            ->with(['booking.service', 'purchase.items'])
            ->first();

        if (! $obligation instanceof FinancialObligation) {
            throw (new ModelNotFoundException)->setModel(FinancialObligation::class, [$obligationId]);
        }

        $target = $this->sellable->handle($obligation);
        if ($target === null) {
            throw ValidationException::withMessages(['obligation' => 'Эту задолженность нельзя оплатить онлайн.']);
        }

        $mapping = $this->mappings->handle(
            (int) $organization->getKey(),
            'lava',
            $target['type'],
            $target['id'],
            $obligation->payment_currency,
        );

        return $this->createAttempt->handle(
            organization: $organization,
            obligation: $obligation,
            gatewayName: 'lava',
            idempotencyKey: $idempotencyKey,
            buyerEmail: (string) $client->email,
            providerOfferId: $mapping->external_offer_id,
            successfulReturnUrl: $successfulReturnUrl,
            failureReturnUrl: $failureReturnUrl,
            cancelReturnUrl: $cancelReturnUrl,
            source: 'client_lava',
        );
    }
}
