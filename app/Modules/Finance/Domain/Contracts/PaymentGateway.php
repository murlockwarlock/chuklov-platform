<?php

namespace App\Modules\Finance\Domain\Contracts;

use App\Modules\Finance\Domain\ValueObjects\GatewayFailureEvidence;
use App\Modules\Finance\Domain\ValueObjects\GatewayInitiationRequest;
use App\Modules\Finance\Domain\ValueObjects\GatewayInitiationResult;
use App\Modules\Finance\Domain\ValueObjects\GatewayReconciliationResult;
use App\Modules\Finance\Domain\ValueObjects\GatewayRefundEvidence;
use App\Modules\Finance\Domain\ValueObjects\GatewayRefundRequest;
use App\Modules\Finance\Domain\ValueObjects\GatewayRefundResult;
use App\Modules\Finance\Domain\ValueObjects\GatewaySettlementEvidence;
use App\Modules\Finance\Domain\ValueObjects\VerifiedGatewayFailure;
use App\Modules\Finance\Domain\ValueObjects\VerifiedGatewayRefund;
use App\Modules\Finance\Domain\ValueObjects\VerifiedGatewaySettlement;

interface PaymentGateway
{
    public function name(): string;

    public function initiate(GatewayInitiationRequest $request): GatewayInitiationResult;

    public function refund(GatewayRefundRequest $request): GatewayRefundResult;

    public function verifyFailure(GatewayFailureEvidence $evidence): VerifiedGatewayFailure;

    public function verifySettlement(GatewaySettlementEvidence $evidence): VerifiedGatewaySettlement;

    public function verifyRefund(GatewayRefundEvidence $evidence): VerifiedGatewayRefund;

    public function reconcile(string $providerReference, int $organizationId): GatewayReconciliationResult;
}
