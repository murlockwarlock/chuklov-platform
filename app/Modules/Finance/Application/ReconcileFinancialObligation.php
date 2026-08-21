<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialStatus;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\ValueObjects\FinancialReconciliation;
use App\Modules\Finance\Domain\ValueObjects\Money;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use UnexpectedValueException;

final class ReconcileFinancialObligation
{
    public function __construct(private readonly FinancialReconciliationContract $contract) {}

    public function handle(int $organizationId, int $obligationId, bool $lock = false): FinancialReconciliation
    {
        $query = FinancialObligation::query()
            ->where('organization_id', $organizationId)
            ->whereKey($obligationId);
        $obligation = ($lock ? $query->lockForUpdate() : $query)->first();

        if ($obligation === null) {
            throw (new ModelNotFoundException)->setModel(FinancialObligation::class, [$obligationId]);
        }

        $obligationData = $this->contract->validateObligation($obligation);
        $settlementCurrency = $obligationData['currencies']['settlement_currency'];
        $baseCurrency = $obligationData['currencies']['base_currency'];
        $displayCurrency = $obligationData['currencies']['display_currency'];
        $appliedMinor = BigInteger::zero();

        $entries = FinancialLedgerEntry::query()
            ->where('organization_id', $organizationId)
            ->where('obligation_id', $obligation->getKey())
            ->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            $ledgerData = $this->contract->validateLedgerForReconciliation($entry);

            if ($ledgerData['currencies']['settlement_currency'] !== $settlementCurrency
                || $ledgerData['currencies']['base_currency'] !== $baseCurrency
                || $ledgerData['currencies']['display_currency'] !== $displayCurrency) {
                throw new UnexpectedValueException('A ledger entry has an incompatible financial currency.');
            }

            $appliedMinor = $appliedMinor->plus((string) $ledgerData['settlement_amount_minor']);
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

        $obligationData = $this->contract->validateObligation($obligation);
        $settlementCurrency = $obligationData['currencies']['settlement_currency'];
        $baseCurrency = $obligationData['currencies']['base_currency'];
        $displayCurrency = $obligationData['currencies']['display_currency'];
        $appliedMinorValue = BigInteger::zero()->plus((string) ($appliedMinor ?? '0'));

        try {
            $obligationMoney = Money::ofMinor($obligationData['amounts']['settlement_amount_minor'], $settlementCurrency);
            $applied = Money::ofMinor($appliedMinorValue->toString(), $settlementCurrency);
            $outstanding = $obligationMoney->subtract($applied);
        } catch (InvalidArgumentException $exception) {
            throw new UnexpectedValueException('The financial ledger does not reconcile to the obligation.', previous: $exception);
        }

        if ($applied->isNegative() || $outstanding->isNegative()) {
            throw new UnexpectedValueException('The financial ledger does not reconcile to the obligation.');
        }

        $baseObligation = Money::ofMinor($obligationData['amounts']['base_amount_minor'], $baseCurrency);
        $baseOutstanding = $this->valueOutstanding(
            $obligation,
            $outstanding,
            $settlementCurrency,
            $obligationData['amounts']['settlement_amount_minor'],
            'base',
            $baseObligation,
        );
        $baseApplied = $baseObligation->subtract($baseOutstanding);
        $displayObligation = Money::ofMinor($obligationData['amounts']['display_amount_minor'], $displayCurrency);
        $displayOutstanding = $this->valueOutstanding(
            $obligation,
            $outstanding,
            $settlementCurrency,
            $obligationData['amounts']['settlement_amount_minor'],
            'display',
            $displayObligation,
        );
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
        int $settlementAmountMinor,
        string $role,
        Money $obligationAmount,
    ): Money {
        $snapshots = $obligation->getAttribute('conversion_snapshots');
        $snapshot = is_array($snapshots) ? ($snapshots[$role] ?? null) : null;

        try {
            $valuation = $this->contract->validateValuationSnapshot($snapshot);
            $convertedOutstanding = $outstanding->convert(
                $valuation['target_currency'],
                $valuation['rate'],
                $valuation['rounding_mode'],
            );
        } catch (InvalidArgumentException|UnexpectedValueException $exception) {
            throw new UnexpectedValueException('The obligation valuation snapshot is invalid.', previous: $exception);
        }

        if ($valuation['source_currency'] !== $settlementCurrency
            || $valuation['target_currency'] !== $obligationAmount->currency()
            || $valuation['source_amount']->minorUnits() !== $settlementAmountMinor
            || $valuation['target_amount']->minorUnits() !== $obligationAmount->minorUnits()
            || $valuation['source_amount']->convert(
                $valuation['target_currency'],
                $valuation['rate'],
                $valuation['rounding_mode'],
            )->minorUnits() !== $obligationAmount->minorUnits()) {
            throw new UnexpectedValueException('The obligation valuation snapshot does not match its immutable amounts.');
        }

        if ($convertedOutstanding->isNegative() || $convertedOutstanding->compareTo($obligationAmount) > 0) {
            throw new UnexpectedValueException('The obligation valuation produced an invalid outstanding amount.');
        }

        return $convertedOutstanding;
    }
}
