<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
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
        $obligationMoney = Money::ofMinor($obligationData['amounts']['settlement_amount_minor'], $settlementCurrency);
        $applied = Money::ofMinor($appliedMinorValue->toString(), $settlementCurrency);
        $outstanding = $obligationMoney->subtract($applied);

        if ($applied->isNegative() || $outstanding->isNegative()) {
            throw new UnexpectedValueException('The financial ledger does not reconcile to the obligation.');
        }

        $baseObligation = Money::ofMinor($obligationData['amounts']['base_amount_minor'], $baseCurrency);
        $baseOutstanding = $this->valueOutstanding($obligation, $outstanding, $settlementCurrency, 'base', $baseObligation);
        $baseApplied = $baseObligation->subtract($baseOutstanding);
        $displayObligation = Money::ofMinor($obligationData['amounts']['display_amount_minor'], $displayCurrency);
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
            $sourceCurrency = $this->contract->currency($snapshot['source_currency'] ?? null);
            $targetCurrency = $this->contract->currency($snapshot['target_currency'] ?? null);
            if (! is_string($snapshot['rounding_mode'] ?? null) && ! is_int($snapshot['rounding_mode'] ?? null)) {
                throw new UnexpectedValueException('The obligation valuation snapshot is invalid.');
            }
            $roundingMode = FinancialRoundingMode::fromMixed($snapshot['rounding_mode']);
            $sourceAmount = $this->contract->money(
                $snapshot['source_amount_minor'] ?? null,
                $sourceCurrency,
                'The obligation valuation snapshot is invalid.',
            );
            $historicalTarget = $this->contract->money(
                $snapshot['target_amount_minor'] ?? null,
                $targetCurrency,
                'The obligation valuation snapshot is invalid.',
            );
            $historicalRate = $this->contract->rate($snapshot['rate'] ?? null);
            $convertedOutstanding = $outstanding->convert($targetCurrency, (string) $historicalRate, $roundingMode);
        } catch (InvalidArgumentException $exception) {
            throw new UnexpectedValueException('The obligation valuation snapshot is invalid.', previous: $exception);
        }

        if ($sourceCurrency !== $settlementCurrency
            || $targetCurrency !== $obligationAmount->currency()
            || $sourceAmount->minorUnits() !== (int) $obligation->getRawOriginal('settlement_amount_minor')
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
