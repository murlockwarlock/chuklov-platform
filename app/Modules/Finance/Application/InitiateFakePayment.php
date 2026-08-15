<?php

namespace App\Modules\Finance\Application;

use App\Models\User;
use App\Modules\Finance\Domain\Contracts\PaymentGateway;
use App\Modules\Finance\Domain\Enums\PaymentGatewayStatus;
use App\Modules\Finance\Domain\Models\FinanceIdempotencyKey;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Models\PaymentGatewayTransaction;
use App\Modules\Finance\Domain\ValueObjects\GatewayInitiationRequest;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InitiateFakePayment
{
    public function __construct(
        private readonly FinanceAuthorization $authorization,
        private readonly ReconcileFinancialObligation $reconciliation,
        private readonly PaymentGateway $gateway,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, FinancialObligation $obligation, string $idempotencyKey): PaymentGatewayTransaction
    {
        $organization = $this->authorization->authorizeManage($actor);
        $this->authorization->assertOwned($obligation);

        if (! config('payments.fake_enabled', true) || $this->gateway->name() !== 'fake') {
            throw ValidationException::withMessages(['gateway' => 'Тестовый шлюз отключён в этом окружении.']);
        }

        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 180 || preg_match('/^[A-Za-z0-9._:-]+$/', $idempotencyKey) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => 'Ключ операции указан неверно.']);
        }

        return DB::transaction(function () use ($actor, $organization, $obligation, $idempotencyKey): PaymentGatewayTransaction {
            $lockedObligation = FinancialObligation::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($obligation->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $current = $this->reconciliation->handle((int) $organization->getKey(), (int) $lockedObligation->getKey(), true);

            if ($current->outstanding->isZero()) {
                throw ValidationException::withMessages(['obligation' => 'Задолженность уже погашена.']);
            }

            $requestHash = hash('sha256', json_encode([
                'obligation_id' => $lockedObligation->getKey(),
                'amount_minor' => $current->outstanding->minorUnitsString(),
                'currency' => $current->outstanding->currency()->value,
            ], JSON_THROW_ON_ERROR));
            $idempotency = FinanceIdempotencyKey::query()
                ->where('organization_id', $organization->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($idempotency !== null) {
                if ($idempotency->operation !== 'fake_gateway_initiation'
                    || $idempotency->subject_type !== FinancialObligation::class
                    || $idempotency->subject_id !== $lockedObligation->getKey()
                    || $idempotency->request_hash !== $requestHash) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Этот ключ уже использован для другой операции.']);
                }

                return PaymentGatewayTransaction::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($idempotency->result_id)
                    ->firstOrFail();
            }

            DB::table('finance_idempotency_keys')->insertOrIgnore([
                'organization_id' => $organization->getKey(),
                'idempotency_key' => $idempotencyKey,
                'operation' => 'fake_gateway_initiation',
                'subject_type' => FinancialObligation::class,
                'subject_id' => $lockedObligation->getKey(),
                'request_hash' => $requestHash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $idempotency = FinanceIdempotencyKey::query()
                ->where('organization_id', $organization->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->firstOrFail();
            if ($idempotency->result_id !== null) {
                if ($idempotency->operation !== 'fake_gateway_initiation'
                    || $idempotency->subject_type !== FinancialObligation::class
                    || $idempotency->subject_id !== $lockedObligation->getKey()
                    || $idempotency->request_hash !== $requestHash) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Этот ключ уже использован для другой операции.']);
                }

                return PaymentGatewayTransaction::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($idempotency->result_id)
                    ->firstOrFail();
            }
            $gatewayResult = $this->gateway->initiate(new GatewayInitiationRequest(
                organizationId: (int) $organization->getKey(),
                obligationId: (int) $lockedObligation->getKey(),
                amountMinor: $current->outstanding->minorUnits(),
                currency: $current->outstanding->currency(),
                idempotencyKey: $idempotencyKey,
            ));
            $transaction = new PaymentGatewayTransaction;
            $transaction->forceFill([
                'organization_id' => $organization->getKey(),
                'obligation_id' => $lockedObligation->getKey(),
                'gateway' => $gatewayResult->gateway,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'provider_reference' => $gatewayResult->providerReference,
                'amount_minor' => $current->outstanding->minorUnits(),
                'currency' => $current->outstanding->currency()->value,
                'settlement_amount_minor' => $current->outstanding->minorUnits(),
                'settlement_currency' => $current->outstanding->currency()->value,
                'status' => PaymentGatewayStatus::Pending->value,
                'created_by_user_id' => $actor->getKey(),
                'initiated_at' => now(),
            ])->save();
            $idempotency->forceFill([
                'result_type' => PaymentGatewayTransaction::class,
                'result_id' => $transaction->getKey(),
                'updated_at' => now(),
            ])->save();
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'finance.gateway.initiated',
                targetType: PaymentGatewayTransaction::class,
                targetId: (string) $transaction->getKey(),
                metadata: [
                    'gateway' => $gatewayResult->gateway,
                    'currency' => $current->outstanding->currency()->value,
                    'source' => 'crm_test_tool',
                ],
            );

            return $transaction->refresh();
        });
    }
}
