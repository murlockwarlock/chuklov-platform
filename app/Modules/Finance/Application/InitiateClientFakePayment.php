<?php

namespace App\Modules\Finance\Application;

use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class InitiateClientFakePayment
{
    public function __construct(
        private readonly ClientPortalContext $clientContext,
        private readonly OrganizationContext $organizationContext,
        private readonly CreateFakePaymentAttempt $createAttempt,
    ) {}

    public function handle(int $obligationId, string $idempotencyKey): void
    {
        $client = $this->clientContext->client();
        $organization = $this->organizationContext->organization();
        $obligation = FinancialObligation::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->whereKey($obligationId)
            ->first();

        if ($obligation === null) {
            throw (new ModelNotFoundException)->setModel(FinancialObligation::class, [$obligationId]);
        }

        $this->createAttempt->handle($organization, $obligation, $idempotencyKey, null, 'client_demo');
    }
}
