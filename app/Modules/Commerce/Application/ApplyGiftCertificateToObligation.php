<?php

namespace App\Modules\Commerce\Application;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\GiftCertificateMovementType;
use App\Modules\Commerce\Domain\Models\GiftCertificate;
use App\Modules\Commerce\Domain\Models\GiftCertificateRedemption;
use App\Modules\Commerce\Domain\Models\PurchaseItem;
use App\Modules\Finance\Application\AppendFinancialLedgerEntry;
use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Application\FinancialReconciliationContract;
use App\Modules\Finance\Application\ReconcileFinancialObligation;
use App\Modules\Finance\Application\RecordFinancialSettlementEvent;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialEntrySource;
use App\Modules\Finance\Domain\Enums\FinancialLedgerEntryType;
use App\Modules\Finance\Domain\Models\FinanceIdempotencyKey;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Finance\Domain\ValueObjects\FinancialLedgerEntryData;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ApplyGiftCertificateToObligation
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly CurrencyCatalog $catalog,
        private readonly CurrencyConfigurationService $configuration,
        private readonly FinancialReconciliationContract $contract,
        private readonly ReconcileFinancialObligation $reconciliation,
        private readonly AppendFinancialLedgerEntry $ledger,
        private readonly RecordFinancialSettlementEvent $settlementEvents,
        private readonly GiftCertificateBalanceProjection $balances,
        private readonly AppendGiftCertificateMovement $movements,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        Client $client,
        GiftCertificate|int $certificate,
        int $obligationId,
        string|int $amount,
        string $currency,
        string $idempotencyKey,
        ?User $actor = null,
        string $source = 'portal',
    ): FinancialLedgerEntry {
        $organization = $this->context->organization();
        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        if (! in_array($source, ['portal', 'crm'], true)) {
            throw ValidationException::withMessages(['source' => 'Источник операции указан неверно.']);
        }

        $certificateId = $certificate instanceof GiftCertificate
            ? (int) $certificate->getKey()
            : $certificate;
        $key = trim($idempotencyKey);
        if ($key === '' || mb_strlen($key) > 180 || preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => 'Ключ операции указан неверно.']);
        }

        $currencyCode = $this->currency($currency);
        $money = $this->money($amount, $currencyCode);
        $requestHash = hash('sha256', json_encode([
            'client_id' => $client->getKey(),
            'certificate_id' => $certificateId,
            'obligation_id' => $obligationId,
            'amount_minor' => $money->minorUnits(),
            'currency' => $currencyCode->value,
            'source' => $source,
        ], JSON_THROW_ON_ERROR));
        $configuration = $this->configuration;
        $contract = $this->contract;
        $reconciliation = $this->reconciliation;
        $ledger = $this->ledger;
        $settlementEvents = $this->settlementEvents;
        $balances = $this->balances;
        $movements = $this->movements;
        $audit = $this->audit;

        return DB::transaction(function () use (
            $organization,
            $client,
            $certificateId,
            $obligationId,
            $currencyCode,
            $money,
            $key,
            $requestHash,
            $actor,
            $source,
            $configuration,
            $contract,
            $reconciliation,
            $ledger,
            $settlementEvents,
            $balances,
            $movements,
            $audit,
        ): FinancialLedgerEntry {
            $lockedCertificate = GiftCertificate::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($certificateId)
                ->lockForUpdate()
                ->first();
            if (! $lockedCertificate instanceof GiftCertificate) {
                throw new AuthorizationException('The gift certificate is outside the current organization.');
            }

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
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();
            if ($idempotency !== null) {
                $this->assertIdempotency($idempotency, $lockedCertificate, $requestHash);

                return $this->existingResult($idempotency, (int) $organization->getKey());
            }

            DB::table('finance_idempotency_keys')->insertOrIgnore([
                'organization_id' => $organization->getKey(),
                'idempotency_key' => $key,
                'operation' => 'gift_certificate_redemption',
                'subject_type' => GiftCertificate::class,
                'subject_id' => $lockedCertificate->getKey(),
                'request_hash' => $requestHash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $idempotency = FinanceIdempotencyKey::query()
                ->where('organization_id', $organization->getKey())
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertIdempotency($idempotency, $lockedCertificate, $requestHash);
            if ($idempotency->result_id !== null) {
                return $this->existingResult($idempotency, (int) $organization->getKey());
            }

            if ((int) $lockedCertificate->current_holder_client_id !== (int) $beneficiary->getKey()) {
                throw new AuthorizationException('The client does not own this gift certificate.');
            }

            $certificateCurrency = CurrencyCode::tryFrom((string) $lockedCertificate->getRawOriginal('currency'));
            if ($certificateCurrency === null || $certificateCurrency !== $currencyCode) {
                throw ValidationException::withMessages([
                    'currency' => 'Сертификат можно применить только в его валюте.',
                ]);
            }

            if ($lockedObligation->booking_id === null && $lockedObligation->purchase_id === null) {
                throw ValidationException::withMessages([
                    'obligation' => 'Сертификат нельзя применить к этому обязательству.',
                ]);
            }

            if ($lockedObligation->purchase_id !== null) {
                $purchase = $lockedObligation->purchase()->with('items')->first();
                if ($purchase === null || $purchase->items->isEmpty()) {
                    throw ValidationException::withMessages([
                        'obligation' => 'Сертификат нельзя применить к этому обязательству.',
                    ]);
                }
                if ($purchase->items->contains(
                    static fn (PurchaseItem $item): bool => is_array($snapshot = $item->getAttribute('product_snapshot'))
                        && ($snapshot['kind'] ?? null) === 'gift_certificate',
                )) {
                    throw ValidationException::withMessages([
                        'obligation' => 'Подарочный сертификат нельзя купить за другой сертификат.',
                    ]);
                }
            }

            $obligationData = $contract->validateObligation($lockedObligation);
            if ($obligationData['currencies']['settlement_currency'] !== $currencyCode) {
                throw ValidationException::withMessages([
                    'currency' => 'Сертификат можно применить только к обязательству в той же валюте.',
                ]);
            }

            $current = $reconciliation->handle(
                (int) $organization->getKey(),
                (int) $lockedObligation->getKey(),
                true,
            );
            $available = $balances->balance($lockedCertificate, true);
            if ($money->compareTo($available) > 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Сумма превышает остаток сертификата.',
                ]);
            }
            if ($money->compareTo($current->outstanding) > 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Сумма сертификата не может превышать текущую задолженность.',
                ]);
            }

            $baseSnapshot = $configuration->convert($organization, $money, $lockedObligation->base_currency);
            $displaySnapshot = $configuration->convert($organization, $money, $lockedObligation->display_currency);
            $occurredAt = CarbonImmutable::now('UTC');
            $entry = $ledger->handle(
                organization: $organization,
                obligation: $lockedObligation,
                data: new FinancialLedgerEntryData(
                    entryType: FinancialLedgerEntryType::GiftCertificateRedemption,
                    source: FinancialEntrySource::GiftCertificate,
                    amountMinor: $money->minorUnits(),
                    currency: $certificateCurrency,
                    paymentAmountMinor: $money->minorUnits(),
                    paymentCurrency: $certificateCurrency,
                    baseAmountMinor: (int) $baseSnapshot->targetAmountMinor,
                    baseCurrency: $baseSnapshot->targetCurrency,
                    displayAmountMinor: (int) $displaySnapshot->targetAmountMinor,
                    displayCurrency: $displaySnapshot->targetCurrency,
                    settlementAmountMinor: $money->minorUnits(),
                    settlementCurrency: $currencyCode,
                    conversionSnapshot: [
                        'base' => $baseSnapshot->toArray(),
                        'display' => $displaySnapshot->toArray(),
                        'certificate_id' => $lockedCertificate->getKey(),
                    ],
                    paymentMethod: null,
                    occurredAt: $occurredAt,
                    note: null,
                    actorUserId: $actor?->getKey(),
                    providerReference: null,
                    idempotencyKey: 'gift_certificate_redemption:'.$organization->getKey().':'.$key,
                ),
            );

            $redemption = new GiftCertificateRedemption;
            $redemption->forceFill([
                'organization_id' => $organization->getKey(),
                'certificate_id' => $lockedCertificate->getKey(),
                'holder_client_id' => $beneficiary->getKey(),
                'financial_obligation_id' => $lockedObligation->getKey(),
                'financial_ledger_entry_id' => $entry->getKey(),
                'amount_minor' => $money->minorUnits(),
                'currency' => $currencyCode->value,
                'idempotency_key' => $key,
                'occurred_at' => $occurredAt,
                'created_by_user_id' => $actor?->getKey(),
            ])->save();

            $movements->handle(
                certificate: $lockedCertificate,
                type: GiftCertificateMovementType::Redeemed,
                amountMinor: $money->minorUnits(),
                currency: $certificateCurrency,
                fromHolderClientId: $beneficiary->getKey(),
                toHolderClientId: null,
                claimId: null,
                redemptionId: $redemption->getKey(),
                reversesMovementId: null,
                actorUserId: $actor?->getKey(),
                idempotencyKey: 'gift_certificate.redeemed:'.$organization->getKey().':'.$redemption->getKey(),
                occurredAt: $occurredAt,
            );

            $settled = $reconciliation->handle(
                (int) $organization->getKey(),
                (int) $lockedObligation->getKey(),
                true,
            );
            if ($settled->outstanding->isNegative()) {
                throw ValidationException::withMessages([
                    'amount' => 'Сертификат нельзя применить к этой задолженности.',
                ]);
            }
            if (! $current->isSettled() && $settled->isSettled()) {
                $settlementEvents->handle($lockedObligation, $entry, $occurredAt, $actor);
            }

            $idempotency->forceFill([
                'result_type' => FinancialLedgerEntry::class,
                'result_id' => $entry->getKey(),
                'updated_at' => now(),
            ])->save();
            $audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'finance.gift_certificate.redeemed',
                targetType: FinancialLedgerEntry::class,
                targetId: (string) $entry->getKey(),
                metadata: [
                    'certificate_id' => $lockedCertificate->getKey(),
                    'client_id' => $beneficiary->getKey(),
                    'obligation_id' => $lockedObligation->getKey(),
                    'amount_minor' => $money->minorUnits(),
                    'currency' => $currencyCode->value,
                    'source' => $source,
                ],
            );
            $audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'gift_certificate.redeemed',
                targetType: GiftCertificateRedemption::class,
                targetId: (string) $redemption->getKey(),
                metadata: [
                    'certificate_id' => $lockedCertificate->getKey(),
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

    private function money(string|int $amount, CurrencyCode $currency): Money
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
        GiftCertificate $certificate,
        string $requestHash,
    ): void {
        if ($idempotency->operation !== 'gift_certificate_redemption'
            || $idempotency->subject_type !== GiftCertificate::class
            || (int) $idempotency->subject_id !== (int) $certificate->getKey()
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
