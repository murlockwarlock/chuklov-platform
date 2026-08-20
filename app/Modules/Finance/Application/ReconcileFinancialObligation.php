<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
use App\Modules\Finance\Domain\Enums\FinancialStatus;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Finance\Domain\ValueObjects\FinancialReconciliation;
use App\Modules\Finance\Domain\ValueObjects\Money;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use UnexpectedValueException;

final class ReconcileFinancialObligation
{
    public function __construct(private readonly CurrencyCatalog $catalog) {}

    public function handle(int $organizationId, int $obligationId, bool $lock = false): FinancialReconciliation
    {
        $query = FinancialObligation::query()
            ->where('organization_id', $organizationId)
            ->whereKey($obligationId);
        $obligation = ($lock ? $query->lockForUpdate() : $query)->first();

        if ($obligation === null) {
            throw (new ModelNotFoundException)->setModel(FinancialObligation::class, [$obligationId]);
        }

        $settlementCurrency = $this->obligationCurrency($obligation, 'settlement_currency');
        $baseCurrency = $this->obligationCurrency($obligation, 'base_currency');
        $displayCurrency = $this->obligationCurrency($obligation, 'display_currency');
        $appliedMinor = BigInteger::zero();

        $entries = FinancialLedgerEntry::query()
            ->where('organization_id', $organizationId)
            ->where('obligation_id', $obligation->getKey())
            ->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            if ($this->ledgerCurrency($entry, 'settlement_currency') !== $settlementCurrency
                || $this->ledgerCurrency($entry, 'base_currency') !== $baseCurrency
                || $this->ledgerCurrency($entry, 'display_currency') !== $displayCurrency) {
                throw new UnexpectedValueException('A ledger entry has an incompatible financial currency.');
            }

            $appliedMinor = $appliedMinor->plus((string) $entry->getRawOriginal('settlement_amount_minor'));
        }

        return $this->handleAggregated($obligation, $appliedMinor->toString());
    }

    public function handleAggregated(
        FinancialObligation $obligation,
        string|int|null $appliedMinor,
        string|int|null $incompatibleLedgerRows = 0,
    ): FinancialReconciliation {
        if ((int) ($incompatibleLedgerRows ?? 0) > 0) {
            throw new UnexpectedValueException('A ledger entry has an incompatible financial currency.');
        }

        $settlementCurrency = $this->obligationCurrency($obligation, 'settlement_currency');
        $baseCurrency = $this->obligationCurrency($obligation, 'base_currency');
        $displayCurrency = $this->obligationCurrency($obligation, 'display_currency');
        $appliedMinorValue = BigInteger::zero()->plus((string) ($appliedMinor ?? '0'));
        $obligationMoney = Money::ofMinor($obligation->settlement_amount_minor, $settlementCurrency);
        $applied = Money::ofMinor($appliedMinorValue->toString(), $settlementCurrency);
        $outstanding = $obligationMoney->subtract($applied);

        if ($applied->isNegative() || $outstanding->isNegative()) {
            throw new UnexpectedValueException('The financial ledger does not reconcile to the obligation.');
        }

        $baseObligation = Money::ofMinor($obligation->base_amount_minor, $baseCurrency);
        $baseOutstanding = $this->valueOutstanding($obligation, $outstanding, $settlementCurrency, 'base', $baseObligation);
        $baseApplied = $baseObligation->subtract($baseOutstanding);
        $displayObligation = Money::ofMinor($obligation->display_amount_minor, $displayCurrency);
        $displayOutstanding = $this->valueOutstanding($obligation, $outstanding, $settlementCurrency, 'display', $displayObligation);
        $displayApplied = $displayObligation->subtract($displayOutstanding);

        if ($baseApplied->isNegative() || $baseOutstanding->isNegative()
            || $displayApplied->isNegative() || $displayOutstanding->isNegative()) {
            throw new UnexpectedValueException('The financial ledger does not reconcile to the obligation.');
        }

        $status = $applied->isZero()
            ? FinancialStatus::Outstanding
            : ($outstanding->isZero() ? FinancialStatus::Settled : FinancialStatus::PartiallyPaid);

        return new FinancialReconciliation(
            obligation: $obligationMoney,
            applied: $applied,
            outstanding: $outstanding,
            status: $status,
            baseApplied: $baseApplied,
            baseOutstanding: $baseOutstanding,
            displayApplied: $displayApplied,
            displayOutstanding: $displayOutstanding,
        );
    }

    private function valueOutstanding(
        FinancialObligation $obligation,
        Money $outstanding,
        CurrencyCode $settlementCurrency,
        string $role,
        Money $obligationAmount,
    ): Money {
        $snapshot = $obligation->conversion_snapshots[$role] ?? null;

        if (! is_array($snapshot)) {
            throw new UnexpectedValueException('The obligation is missing its immutable valuation snapshot.');
        }

        try {
            $sourceCurrency = $this->catalog->code($snapshot['source_currency'] ?? null);
            $targetCurrency = $this->catalog->code($snapshot['target_currency'] ?? null);
            $roundingMode = FinancialRoundingMode::fromMixed($snapshot['rounding_mode'] ?? null);
            $sourceAmount = Money::ofMinor($snapshot['source_amount_minor'] ?? null, $sourceCurrency);
            $historicalTarget = Money::ofMinor($snapshot['target_amount_minor'] ?? null, $targetCurrency);
            $historicalRate = $snapshot['rate'] ?? null;
            $convertedOutstanding = $outstanding->convert($targetCurrency, (string) $historicalRate, $roundingMode);
        } catch (\Throwable $exception) {
            throw new UnexpectedValueException('The obligation valuation snapshot is invalid.', previous: $exception);
        }

        if ($sourceCurrency !== $settlementCurrency
            || $targetCurrency !== $obligationAmount->currency()
            || $sourceAmount->minorUnits() !== $obligation->settlement_amount_minor
            || $historicalTarget->minorUnits() !== $obligationAmount->minorUnits()
            || $sourceAmount->convert($targetCurrency, (string) $historicalRate, $roundingMode)->minorUnits() !== $obligationAmount->minorUnits()) {
            throw new UnexpectedValueException('The obligation valuation snapshot does not match its immutable amounts.');
        }

        if ($convertedOutstanding->isNegative() || $convertedOutstanding->compareTo($obligationAmount) > 0) {
            throw new UnexpectedValueException('The obligation valuation produced an invalid outstanding amount.');
        }

        return $convertedOutstanding;
    }

    private function obligationCurrency(FinancialObligation $obligation, string $attribute): CurrencyCode
    {
        return $this->currencyValue($obligation->getRawOriginal($attribute));
    }

    private function ledgerCurrency(FinancialLedgerEntry $entry, string $attribute): CurrencyCode
    {
        return $this->currencyValue($entry->getRawOriginal($attribute));
    }

    private function currencyValue(mixed $value): CurrencyCode
    {
        try {
            return $this->catalog->code($value);
        } catch (InvalidArgumentException $exception) {
            throw new UnexpectedValueException('A persisted financial currency is invalid.', previous: $exception);
        }
    }
}
