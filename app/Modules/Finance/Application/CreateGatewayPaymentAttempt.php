<?php

namespace App\Modules\Finance\Application;

use App\Models\User;
use App\Modules\Finance\Domain\Contracts\PaymentGatewayRegistry;
use App\Modules\Finance\Domain\Enums\PaymentGatewayStatus;
use App\Modules\Finance\Domain\Exceptions\PaymentGatewayInitiationFailure;
use App\Modules\Finance\Domain\Models\FinanceIdempotencyKey;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Models\PaymentGatewayTransaction;
use App\Modules\Finance\Domain\ValueObjects\GatewayInitiationRequest;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CreateGatewayPaymentAttempt
{
    public function __construct(
        private readonly ReconcileFinancialObligation $reconciliation,
        private readonly PaymentGatewayRegistry $gateways,
        private readonly RecordAuditEvent $audit,
        private readonly DrainPaymentGatewayEvents $drain,
        private readonly RecordScenarioEvent $scenarioEvents,
    ) {}

    public function handle(
        Organization $organization,
        FinancialObligation $obligation,
        string $gatewayName,
        string $idempotencyKey,
        string $buyerEmail,
        string $providerOfferId,
        ?string $successfulReturnUrl = null,
        ?string $failureReturnUrl = null,
        ?string $cancelReturnUrl = null,
        ?User $actor = null,
        string $source = 'application',
    ): PaymentGatewayTransaction {
        $this->assertIdempotencyKey($idempotencyKey);
        $this->assertBuyerEmail($buyerEmail);
        $this->assertProviderOfferId($providerOfferId);

        $gateway = $this->gateways->resolve($gatewayName);
        $prepared = DB::transaction(function () use (
            $organization,
            $obligation,
            $gatewayName,
            $idempotencyKey,
            $buyerEmail,
            $providerOfferId,
            $successfulReturnUrl,
            $failureReturnUrl,
            $cancelReturnUrl,
            $actor,
            $gateway,
        ): array {
            $lockedObligation = FinancialObligation::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($obligation->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $idempotency = FinanceIdempotencyKey::query()
                ->where('organization_id', $organization->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($idempotency !== null) {
                $this->assertMatchingSubject($idempotency, $lockedObligation);
                if ($idempotency->result_id === null) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Эта операция ещё обрабатывается.']);
                }

                $existingTransaction = PaymentGatewayTransaction::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($idempotency->result_id)
                    ->firstOrFail();
                $requestHash = $this->requestHash(
                    $lockedObligation,
                    $gatewayName,
                    Money::ofMinor($existingTransaction->amount_minor, $existingTransaction->currency),
                    $buyerEmail,
                    $providerOfferId,
                    $successfulReturnUrl,
                    $failureReturnUrl,
                    $cancelReturnUrl,
                );
                $this->assertMatchingRequestHash($idempotency, $requestHash, $existingTransaction);

                return [
                    'transaction' => $existingTransaction,
                    'initiate' => false,
                ];
            }

            $current = $this->reconciliation->handle(
                (int) $organization->getKey(),
                (int) $lockedObligation->getKey(),
                true,
            );

            if ($current->outstanding->isZero()) {
                throw ValidationException::withMessages(['obligation' => 'Задолженность уже погашена.']);
            }

            $requestHash = $this->requestHash(
                $lockedObligation,
                $gatewayName,
                $current->outstanding,
                $buyerEmail,
                $providerOfferId,
                $successfulReturnUrl,
                $failureReturnUrl,
                $cancelReturnUrl,
            );

            DB::table('finance_idempotency_keys')->insert([
                'organization_id' => $organization->getKey(),
                'idempotency_key' => $idempotencyKey,
                'operation' => 'gateway_initiation',
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
            $transaction = new PaymentGatewayTransaction;
            $transaction->forceFill([
                'organization_id' => $organization->getKey(),
                'obligation_id' => $lockedObligation->getKey(),
                'gateway' => $gateway->name(),
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'provider_reference' => null,
                'checkout_url' => null,
                'amount_minor' => $current->outstanding->minorUnits(),
                'currency' => $current->outstanding->currency()->value,
                'settlement_amount_minor' => $current->outstanding->minorUnits(),
                'settlement_currency' => $current->outstanding->currency()->value,
                'status' => PaymentGatewayStatus::Initiating->value,
                'created_by_user_id' => $actor?->getKey(),
                'initiated_at' => now(),
            ])->save();
            $idempotency->forceFill([
                'result_type' => PaymentGatewayTransaction::class,
                'result_id' => $transaction->getKey(),
                'updated_at' => now(),
            ])->save();

            return [
                'transaction' => $transaction->refresh(),
                'initiate' => true,
                'request' => new GatewayInitiationRequest(
                    organizationId: (int) $organization->getKey(),
                    obligationId: (int) $lockedObligation->getKey(),
                    amountMinor: $current->outstanding->minorUnits(),
                    currency: $current->outstanding->currency(),
                    idempotencyKey: $idempotencyKey,
                    buyerEmail: $buyerEmail,
                    providerOfferId: $providerOfferId,
                    successfulReturnUrl: $successfulReturnUrl,
                    failureReturnUrl: $failureReturnUrl,
                    cancelReturnUrl: $cancelReturnUrl,
                ),
            ];
        });

        $transaction = $prepared['transaction'];
        if (! $prepared['initiate']) {
            return $transaction;
        }

        try {
            $result = $gateway->initiate($prepared['request']);

            $transaction = DB::transaction(function () use ($organization, $transaction, $result): PaymentGatewayTransaction {
                $locked = PaymentGatewayTransaction::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($transaction->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->provider_reference !== null || $locked->status !== PaymentGatewayStatus::Initiating) {
                    return $locked;
                }

                if ($result->gateway !== $locked->gateway || trim($result->providerReference) === '') {
                    throw ValidationException::withMessages(['gateway' => 'Шлюз вернул некорректную ссылку на операцию.']);
                }

                $locked->forceFill([
                    'provider_reference' => $result->providerReference,
                    'checkout_url' => $result->checkoutUrl,
                    'status' => PaymentGatewayStatus::Pending->value,
                    'last_error' => null,
                    'updated_at' => now(),
                ])->save();

                return $locked->refresh();
            });
        } catch (Throwable $exception) {
            $this->markUnknown($organization, $transaction, $exception);

            if ($exception instanceof PaymentGatewayInitiationFailure && $exception->shouldNotifyOperations()) {
                $this->scenarioEvents->paymentInitiationUnavailable(
                    organizationId: (int) $organization->getKey(),
                    gateway: $transaction->gateway,
                    reason: $exception->reasonCode(),
                    occurredAt: now()->toImmutable(),
                    obligation: $obligation,
                    transaction: $transaction,
                    deduplicationKey: 'gateway:'.$transaction->gateway.':'.$exception->reasonCode(),
                );
            }

            throw $exception;
        }

        if ($transaction->provider_reference !== null) {
            $this->drain->handle(
                (int) $organization->getKey(),
                $transaction->gateway,
                $transaction->provider_reference,
            );
        }

        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: 'finance.gateway.initiated',
            targetType: PaymentGatewayTransaction::class,
            targetId: (string) $transaction->getKey(),
            metadata: [
                'gateway' => $transaction->gateway,
                'currency' => $transaction->currency->value,
                'source' => $source,
            ],
        );

        return $transaction->refresh();
    }

    private function markUnknown(Organization $organization, PaymentGatewayTransaction $transaction, Throwable $exception): void
    {
        DB::transaction(function () use ($organization, $transaction, $exception): void {
            PaymentGatewayTransaction::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->update([
                    'status' => PaymentGatewayStatus::Unknown->value,
                    'last_error' => $exception instanceof PaymentGatewayInitiationFailure
                        ? $exception->getMessage()
                        : 'Payment initiation failed.',
                    'updated_at' => now(),
                ]);
        });
    }

    private function requestHash(
        FinancialObligation $obligation,
        string $gatewayName,
        Money $amount,
        string $buyerEmail,
        string $providerOfferId,
        ?string $successfulReturnUrl,
        ?string $failureReturnUrl,
        ?string $cancelReturnUrl,
    ): string {
        return hash('sha256', json_encode([
            'obligation_id' => $obligation->getKey(),
            'gateway' => $gatewayName,
            'amount_minor' => $amount->minorUnitsString(),
            'currency' => $amount->currency()->value,
            'buyer_email' => mb_strtolower(trim($buyerEmail)),
            'provider_offer_id' => trim($providerOfferId),
            'successful_return_url' => $successfulReturnUrl,
            'failure_return_url' => $failureReturnUrl,
            'cancel_return_url' => $cancelReturnUrl,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertMatchingSubject(FinanceIdempotencyKey $idempotency, FinancialObligation $obligation): void
    {
        if ($idempotency->operation !== 'gateway_initiation'
            || $idempotency->subject_type !== FinancialObligation::class
            || $idempotency->subject_id !== $obligation->getKey()) {
            throw ValidationException::withMessages(['idempotency_key' => 'Этот ключ уже использован для другой операции.']);
        }
    }

    private function assertMatchingRequestHash(
        FinanceIdempotencyKey $idempotency,
        string $requestHash,
        PaymentGatewayTransaction $transaction,
    ): void {
        if ($idempotency->request_hash !== $requestHash || $transaction->request_hash !== $requestHash) {
            throw ValidationException::withMessages(['idempotency_key' => 'Этот ключ уже использован для другой операции.']);
        }
    }

    private function assertIdempotencyKey(string $idempotencyKey): void
    {
        if (trim($idempotencyKey) === '' || mb_strlen($idempotencyKey) > 180 || preg_match('/^[A-Za-z0-9._:-]+$/', $idempotencyKey) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => 'Ключ операции указан неверно.']);
        }
    }

    private function assertBuyerEmail(string $buyerEmail): void
    {
        if (filter_var($buyerEmail, FILTER_VALIDATE_EMAIL) === false || mb_strlen($buyerEmail) > 320) {
            throw ValidationException::withMessages(['email' => 'Email клиента указан неверно.']);
        }
    }

    private function assertProviderOfferId(string $providerOfferId): void
    {
        if (trim($providerOfferId) === '' || mb_strlen($providerOfferId) > 180) {
            throw ValidationException::withMessages(['provider_offer_id' => 'Идентификатор предложения указан неверно.']);
        }
    }
}
