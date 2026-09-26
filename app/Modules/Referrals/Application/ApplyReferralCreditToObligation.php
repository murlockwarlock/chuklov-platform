<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Finance\Application\AppendFinancialLedgerEntry;
use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Application\FinancialReconciliationContract;
use App\Modules\Finance\Application\ReconcileFinancialObligation;
use App\Modules\Finance\Application\RecordFinancialSettlementEvent;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialEntrySource;
use App\Modules\Finance\Domain\Enums\FinancialLedgerEntryType;
use App\Modules\Finance\Domain\Enums\PaymentMethod;
use App\Modules\Finance\Domain\Models\FinanceIdempotencyKey;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Finance\Domain\ValueObjects\FinancialLedgerEntryData;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Domain\Enums\ReferralRewardCategory;
use App\Modules\Referrals\Domain\Enums\ReferralRewardLedgerEntryType;
use App\Modules\Referrals\Domain\Models\ReferralRewardLedgerEntry;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ApplyReferralCreditToObligation
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly CurrencyCatalog $catalog,
        private readonly CurrencyConfigurationService $configuration,
        private readonly ReconcileFinancialObligation $reconciliation,
        private readonly FinancialReconciliationContract $contract,
        private readonly AppendFinancialLedgerEntry $ledger,
        private readonly RecordFinancialSettlementEvent $settlementEvents,
        private readonly ReferralRewardBalanceProjection $balances,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(Client $client, int $obligationId, string $amount, string $currency, string $idempotencyKey): FinancialLedgerEntry
    {
        $organization = $this->context->organization();

        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            abort(403);
        }

        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === ''
            || mb_strlen($idempotencyKey) > 180
            || preg_match('/^[A-Za-z0-9._:-]+$/', $idempotencyKey) !== 1) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Ключ операции указан неверно.',
            ]);
        }

        $currencyCode = $this->currency($currency);
        $money = $this->money($amount, $currencyCode);
        $requestHash = hash('sha256', json_encode([
            'client_id' => $client->getKey(),
            'obligation_id' => $obligationId,
            'amount_minor' => $money->minorUnits(),
            'currency' => $currencyCode->value,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $client,
            $organization,
            $obligationId,
            $currencyCode,
            $money,
            $idempotencyKey,
            $requestHash,
        ): FinancialLedgerEntry {
            $beneficiary = Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($client->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $lockedObligation = FinancialObligation::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $beneficiary->getKey())
                ->whereKey($obligationId)
                ->lockForUpdate()
                ->first();

            if (! $lockedObligation instanceof FinancialObligation) {
                throw (new ModelNotFoundException)->setModel(FinancialObligation::class, [$obligationId]);
            }

            $idempotency = FinanceIdempotencyKey::query()
                ->where('organization_id', $organization->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($idempotency !== null) {
                $this->assertIdempotency($idempotency, $lockedObligation, $requestHash);

                return $this->existingResult($idempotency, $organization->getKey());
            }

            DB::table('finance_idempotency_keys')->insertOrIgnore([
                'organization_id' => $organization->getKey(),
                'idempotency_key' => $idempotencyKey,
                'operation' => 'referral_credit_redemption',
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
            $this->assertIdempotency($idempotency, $lockedObligation, $requestHash);

            if ($idempotency->result_id !== null) {
                return $this->existingResult($idempotency, $organization->getKey());
            }

            $obligationData = $this->contract->validateObligation($lockedObligation);
            if ($obligationData['currencies']['settlement_currency'] !== $currencyCode) {
                throw ValidationException::withMessages([
                    'currency' => 'Бонус можно применить только в валюте обязательства.',
                ]);
            }

            $current = $this->reconciliation->handle(
                (int) $organization->getKey(),
                (int) $lockedObligation->getKey(),
                true,
            );
            $available = $this->balances->forCurrency(
                $beneficiary,
                $currencyCode,
                ReferralRewardCategory::ServiceCredit,
            )->available();

            if ($money->compareTo($available) > 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Сумма превышает доступный остаток бонусов.',
                ]);
            }

            if ($money->compareTo($current->outstanding) > 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Сумма бонусов не может превышать текущую задолженность.',
                ]);
            }

            $baseSnapshot = $this->configuration->convert($organization, $money, $lockedObligation->base_currency);
            $displaySnapshot = $this->configuration->convert($organization, $money, $lockedObligation->display_currency);
            $settlementSnapshot = $this->configuration->convert($organization, $money, $lockedObligation->settlement_currency);
            $occurredAt = CarbonImmutable::now();
            $entry = $this->ledger->handle(
                organization: $organization,
                obligation: $lockedObligation,
                data: new FinancialLedgerEntryData(
                    entryType: FinancialLedgerEntryType::ReferralCredit,
                    source: FinancialEntrySource::Referral,
                    amountMinor: $money->minorUnits(),
                    currency: $currencyCode,
                    paymentAmountMinor: $money->minorUnits(),
                    paymentCurrency: $currencyCode,
                    baseAmountMinor: (int) $baseSnapshot->targetAmountMinor,
                    baseCurrency: $baseSnapshot->targetCurrency,
                    displayAmountMinor: (int) $displaySnapshot->targetAmountMinor,
                    displayCurrency: $displaySnapshot->targetCurrency,
                    settlementAmountMinor: (int) $settlementSnapshot->targetAmountMinor,
                    settlementCurrency: $settlementSnapshot->targetCurrency,
                    conversionSnapshot: [
                        'base' => $baseSnapshot->toArray(),
                        'display' => $displaySnapshot->toArray(),
                        'settlement' => $settlementSnapshot->toArray(),
                    ],
                    paymentMethod: PaymentMethod::ReferralCredit,
                    occurredAt: $occurredAt,
                    note: null,
                    actorUserId: null,
                    providerReference: null,
                    idempotencyKey: 'referral_credit:'.$organization->getKey().':'.$idempotencyKey,
                ),
            );

            $reward = new ReferralRewardLedgerEntry;
            $reward->forceFill([
                'organization_id' => $organization->getKey(),
                'beneficiary_client_id' => $beneficiary->getKey(),
                'referred_client_id' => null,
                'referral_relationship_id' => null,
                'referral_commercial_evidence_id' => null,
                'financial_obligation_id' => $lockedObligation->getKey(),
                'financial_ledger_entry_id' => $entry->getKey(),
                'reward_program_version_id' => null,
                'entry_type' => ReferralRewardLedgerEntryType::Redeemed->value,
                'reward_category' => ReferralRewardCategory::ServiceCredit->value,
                'amount_minor' => $money->minorUnits(),
                'currency' => $currencyCode->value,
                'reason_type' => 'credit_redemption',
                'reason' => null,
                'comment' => null,
                'created_by_user_id' => null,
                'reverses_entry_id' => null,
                'idempotency_key' => 'referral.reward.redeemed:'.$organization->getKey().':'.$entry->getKey(),
                'request_hash' => hash('sha256', $entry->getKey().'|'.$money->minorUnitsString().'|'.$currencyCode->value),
                'occurred_at' => $occurredAt,
            ]);
            $reward->save();
            $settled = $this->reconciliation->handle(
                (int) $organization->getKey(),
                (int) $lockedObligation->getKey(),
                true,
            );

            if ($settled->outstanding->isNegative()) {
                throw ValidationException::withMessages(['amount' => 'Бонус нельзя применить к этой задолженности.']);
            }

            if (! $current->isSettled() && $settled->isSettled()) {
                $this->settlementEvents->handle($lockedObligation, $entry, $occurredAt);
            }

            $idempotency->forceFill([
                'result_type' => FinancialLedgerEntry::class,
                'result_id' => $entry->getKey(),
                'updated_at' => now(),
            ])->save();
            $this->audit->handle(
                organization: $organization,
                actor: null,
                action: 'finance.referral_credit.applied',
                targetType: FinancialLedgerEntry::class,
                targetId: (string) $entry->getKey(),
                metadata: [
                    'client_id' => $beneficiary->getKey(),
                    'obligation_id' => $lockedObligation->getKey(),
                    'amount_minor' => $money->minorUnits(),
                    'currency' => $currencyCode->value,
                ],
            );
            $this->audit->handle(
                organization: $organization,
                actor: null,
                action: 'referral.reward.redeemed',
                targetType: ReferralRewardLedgerEntry::class,
                targetId: (string) $reward->getKey(),
                metadata: [
                    'client_id' => $beneficiary->getKey(),
                    'obligation_id' => $lockedObligation->getKey(),
                    'financial_ledger_entry_id' => $entry->getKey(),
                    'amount_minor' => $money->minorUnits(),
                    'currency' => $currencyCode->value,
                ],
            );

            return $entry->refresh();
        });
    }

    private function currency(string $value): CurrencyCode
    {
        try {
            return $this->catalog->code($value);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['currency' => 'Выберите допустимую валюту.']);
        }
    }

    private function money(string $amount, CurrencyCode $currency): Money
    {
        try {
            $money = Money::fromDecimal($amount, $currency);
            $money->assertPositive();

            return $money;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount' => 'Укажите положительную сумму в допустимом формате.']);
        }
    }

    private function assertIdempotency(
        FinanceIdempotencyKey $idempotency,
        FinancialObligation $obligation,
        string $requestHash,
    ): void {
        if ($idempotency->operation !== 'referral_credit_redemption'
            || $idempotency->subject_type !== FinancialObligation::class
            || (int) $idempotency->subject_id !== (int) $obligation->getKey()
            || $idempotency->request_hash !== $requestHash) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Этот ключ уже использован для другой операции.',
            ]);
        }
    }

    private function existingResult(FinanceIdempotencyKey $idempotency, int $organizationId): FinancialLedgerEntry
    {
        if ($idempotency->result_id === null) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Эта операция ещё обрабатывается.',
            ]);
        }

        return FinancialLedgerEntry::query()
            ->where('organization_id', $organizationId)
            ->whereKey($idempotency->result_id)
            ->firstOrFail();
    }
}
