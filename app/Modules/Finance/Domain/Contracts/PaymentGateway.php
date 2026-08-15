<?php

namespace App\Modules\Finance\Domain\Contracts;

use App\Modules\Finance\Domain\ValueObjects\GatewayInitiationRequest;
use App\Modules\Finance\Domain\ValueObjects\GatewayInitiationResult;
use App\Modules\Finance\Domain\ValueObjects\GatewayReconciliationResult;
use App\Modules\Finance\Domain\ValueObjects\GatewaySettlementEvidence;
use App\Modules\Finance\Domain\ValueObjects\VerifiedGatewaySettlement;

interface PaymentGateway
{
    public function name(): string;

    public function initiate(GatewayInitiationRequest $request): GatewayInitiationResult;

    public function verifySettlement(GatewaySettlementEvidence $evidence): VerifiedGatewaySettlement;

    public function reconcile(string $providerReference, int $organizationId): GatewayReconciliationResult;
}
