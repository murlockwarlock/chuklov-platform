<?php

namespace App\Modules\Finance\Application;

use App\Models\User;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Models\PaymentGatewayTransaction;

final class InitiateFakePayment
{
    public function __construct(
        private readonly FinanceAuthorization $authorization,
        private readonly CreateFakePaymentAttempt $createAttempt,
    ) {}

    public function handle(User $actor, FinancialObligation $obligation, string $idempotencyKey): PaymentGatewayTransaction
    {
        $organization = $this->authorization->authorizeManage($actor);
        $this->authorization->assertOwned($obligation);

        return $this->createAttempt->handle($organization, $obligation, $idempotencyKey, $actor, 'crm_test_tool');
    }
}
