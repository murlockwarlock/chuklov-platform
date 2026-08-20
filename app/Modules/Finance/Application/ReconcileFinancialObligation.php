<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
use App\Modules\Finance\Domain\Enums\FinancialStatus;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Finance\Domain\ValueObjects\FinancialReconciliation;
use App\Modules\Finance\Domain\ValueObjects\Money;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

        $appliedMinor = BigInteger::zero();

        $entries = FinancialLedgerEntry::query()
            ->where('organization_id', $organizationId)
            ->where('obligation_id', $obligation->getKey())
            ->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            if ($entry->settlement_currency !== $obligation->settlement_currency
                || $entry->base_currency !== $obligation->base_currency
                || $entry->display_currency !== $obligation->display_currency) {
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

        $appliedMinorValue = BigInteger::zero()->plus((string) ($appliedMinor ?? '0'));
        $obligationMoney = Money::ofMinor($obligation->settlement_amount_minor, $obligation->settlement_currency);
        $applied = Money::ofMinor($appliedMinorValue->toString(), $obligation->settlement_currency);
        $outstanding = $obligationMoney->subtract($applied);

        if ($applied->isNegative() || $outstanding->isNegative()) {
            throw new UnexpectedValueException('The financial ledger does not reconcile to the obligation.');
        }

        $baseObligation = Money::ofMinor($obligation->base_amount_minor, $obligation->base_currency);
        $baseOutstanding = $this->valueOutstanding($obligation, $outstanding, 'base', $baseObligation);
        $baseApplied = $baseObligation->subtract($baseOutstanding);
        $displayObligation = Money::ofMinor($obligation->display_amount_minor, $obligation->display_currency);
        $displayOutstanding = $this->valueOutstanding($obligation, $outstanding, 'display', $displayObligation);
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

        if ($sourceCurrency !== $obligation->settlement_currency
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
}
