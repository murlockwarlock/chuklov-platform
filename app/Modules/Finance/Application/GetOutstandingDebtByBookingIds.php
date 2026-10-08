<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use UnexpectedValueException;

final class GetOutstandingDebtByBookingIds
{
    public function __construct(
        private readonly FinancialReconciliationProjection $projection,
        private readonly ReconcileFinancialObligation $reconciliation,
    ) {}

    /** @param list<int> $bookingIds
     * @return array<int, bool>
     */
    public function handle(int $organizationId, array $bookingIds): array
    {
        $bookingIds = array_values(array_unique(array_filter(array_map('intval', $bookingIds), static fn (int $id): bool => $id > 0)));

        if ($bookingIds === []) {
            return [];
        }

        $obligationTable = (new FinancialObligation)->getTable();
        $ledgerTable = (new FinancialLedgerEntry)->getTable();
        $incompatibleLedgerRows = $this->projection->incompatibleLedgerRowsQuery($obligationTable, $ledgerTable);
        $obligations = FinancialObligation::query()
            ->select($obligationTable.'.*')
            ->where($obligationTable.'.organization_id', $organizationId)
            ->whereIn($obligationTable.'.booking_id', $bookingIds)
            ->addSelect(['crm_incompatible_ledger_rows' => $incompatibleLedgerRows])
            ->get();
        $obligationIds = array_values(array_map('intval', $obligations->modelKeys()));
        $ledgerTotals = collect();
        if ($obligationIds !== []) {
            $ledgerTotals = $this->projection
                ->aggregatedLedgerQuery($organizationId, $obligationIds)
                ->get()
                ->keyBy('obligation_id');
        }
        $result = array_fill_keys($bookingIds, false);

        /** @var FinancialObligation $obligation */
        foreach ($obligations as $obligation) {
            $ledgerTotal = $ledgerTotals->get($obligation->getKey());
            try {
                $current = $this->reconciliation->handleAggregated(
                    $obligation,
                    $ledgerTotal?->getAttribute('applied_settlement_minor'),
                    $obligation->getAttribute('crm_incompatible_ledger_rows'),
                );
            } catch (UnexpectedValueException) {
                continue;
            }

            if ($current->outstanding->isPositive()) {
                $result[(int) $obligation->booking_id] = true;
            }
        }

        return $result;
    }
}
