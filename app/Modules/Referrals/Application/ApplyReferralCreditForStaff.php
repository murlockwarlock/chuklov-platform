<?php

namespace App\Modules\Referrals\Application;

use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\FinancialReconciliationContract;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\Client;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

final class ApplyReferralCreditForStaff
{
    public function __construct(
        private readonly FinanceAuthorization $authorization,
        private readonly FinancialReconciliationContract $contract,
        private readonly ApplyReferralCreditToObligation $redemption,
    ) {}

    public function handle(
        User $actor,
        FinancialObligation|int $obligation,
        string $amount,
        string $idempotencyKey,
    ): FinancialLedgerEntry {
        $organization = $this->authorization->authorizeManage($actor);
        $obligationId = $obligation instanceof FinancialObligation
            ? (int) $obligation->getKey()
            : $obligation;

        if ($obligation instanceof FinancialObligation) {
            $this->authorization->assertOwned($obligation);
        }

        $subject = FinancialObligation::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($obligationId)
            ->first();

        if (! $subject instanceof FinancialObligation) {
            throw (new ModelNotFoundException)->setModel(FinancialObligation::class, [$obligationId]);
        }

        $client = Client::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($subject->getRawOriginal('client_id'))
            ->first();

        if (! $client instanceof Client) {
            throw (new ModelNotFoundException)->setModel(Client::class, [$subject->getRawOriginal('client_id')]);
        }

        try {
            $currency = $this->contract->currency($subject->getRawOriginal('settlement_currency'));
        } catch (\UnexpectedValueException $exception) {
            throw ValidationException::withMessages([
                'currency' => 'Валюта обязательства недоступна для списания бонусов.',
            ]);
        }

        return $this->redemption->handle(
            client: $client,
            obligationId: (int) $subject->getKey(),
            amount: $amount,
            currency: $currency->value,
            idempotencyKey: $idempotencyKey,
            actor: $actor,
            source: 'crm',
        );
    }
}
