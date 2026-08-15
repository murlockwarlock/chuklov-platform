<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\FinancialStatus;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\ValueObjects\FinancialReconciliation;
use App\Modules\Finance\Domain\ValueObjects\Money;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use UnexpectedValueException;

final class ReconcileFinancialObligation
{
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
        $baseAppliedMinor = BigInteger::zero();
        $displayAppliedMinor = BigInteger::zero();

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
            $baseAppliedMinor = $baseAppliedMinor->plus((string) $entry->getRawOriginal('base_amount_minor'));
            $displayAppliedMinor = $displayAppliedMinor->plus((string) $entry->getRawOriginal('display_amount_minor'));
        }

        $obligationMoney = Money::ofMinor($obligation->settlement_amount_minor, $obligation->settlement_currency);
        $applied = Money::ofMinor($appliedMinor->toString(), $obligation->settlement_currency);
        $outstanding = $obligationMoney->subtract($applied);

        if ($applied->isNegative() || $outstanding->isNegative()) {
            throw new UnexpectedValueException('The financial ledger does not reconcile to the obligation.');
        }

        $baseObligation = Money::ofMinor($obligation->base_amount_minor, $obligation->base_currency);
        $baseApplied = Money::ofMinor($baseAppliedMinor->toString(), $obligation->base_currency);
        $baseOutstanding = $baseObligation->subtract($baseApplied);
        $displayObligation = Money::ofMinor($obligation->display_amount_minor, $obligation->display_currency);
        $displayApplied = Money::ofMinor($displayAppliedMinor->toString(), $obligation->display_currency);
        $displayOutstanding = $displayObligation->subtract($displayApplied);

        if ($baseApplied->isNegative() || $displayApplied->isNegative()) {
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
}
