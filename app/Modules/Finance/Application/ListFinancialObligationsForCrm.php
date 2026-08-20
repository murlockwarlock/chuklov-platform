<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\FinancialStatus;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Scheduling\Domain\Models\Booking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;

final class ListFinancialObligationsForCrm
{
    public function __construct(private readonly FinancialReconciliationProjection $projection) {}

    /** @return Builder<FinancialObligation> */
    public function query(int $organizationId): Builder
    {
        $obligationTable = (new FinancialObligation)->getTable();
        $ledgerTable = (new FinancialLedgerEntry)->getTable();
        $bookingTable = (new Booking)->getTable();

        $appliedSettlement = $this->projection->appliedSettlementQuery($obligationTable, $ledgerTable);
        $incompatibleLedgerRows = $this->projection->incompatibleLedgerRowsQuery($obligationTable, $ledgerTable);

        return FinancialObligation::query()
            ->select($obligationTable.'.*')
            ->where($obligationTable.'.organization_id', $organizationId)
            ->addSelect([
                'crm_applied_settlement_minor' => $appliedSettlement,
                'crm_incompatible_ledger_rows' => $incompatibleLedgerRows,
            ])
            ->with(['client', 'booking.service', 'service'])
            ->orderByDesc(
                Booking::query()
                    ->select($bookingTable.'.starts_at')
                    ->whereColumn($bookingTable.'.organization_id', $obligationTable.'.organization_id')
                    ->whereColumn($bookingTable.'.id', $obligationTable.'.booking_id')
                    ->limit(1),
            )
            ->orderByDesc($obligationTable.'.id');
    }

    /**
     * @param  Builder<FinancialObligation>  $query
     * @return Builder<FinancialObligation>
     */
    public function applyStatusFilter(Builder $query, ?string $status): Builder
    {
        if ($status === null || $status === '') {
            return $query;
        }

        $this->projection->applyValidReconciliationFilter($query);
        $obligationTable = (new FinancialObligation)->getTable();
        $ledgerTable = (new FinancialLedgerEntry)->getTable();
        $appliedSettlement = $this->projection->appliedSettlementQuery($obligationTable, $ledgerTable);
        $zero = new Expression('0');
        $obligationAmount = new Expression('financial_obligations.settlement_amount_minor');

        switch ($status) {
            case FinancialStatus::Outstanding->value:
                $query->where($zero, '=', $appliedSettlement);
                break;
            case FinancialStatus::PartiallyPaid->value:
                $query->where($zero, '<', $appliedSettlement);
                $query->where($obligationAmount, '>', $appliedSettlement);
                break;
            case FinancialStatus::Settled->value:
                $query->where($obligationAmount, '=', $appliedSettlement);
                break;
            default:
                $query->whereKey(0);
                break;
        }

        return $query;
    }
}
