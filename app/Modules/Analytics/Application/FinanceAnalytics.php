<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\Data\FinanceAnalyticsData;
use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Application\CurrentCurrencyConfigurationIntegrity;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\FinancialReconciliationProjection;
use App\Modules\Finance\Domain\Enums\FinancialLedgerEntryType;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\Client;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class FinanceAnalytics
{
    public function __construct(
        private readonly FinanceAuthorization $authorization,
        private readonly CurrentCurrencyConfigurationIntegrity $integrity,
        private readonly CurrencyConfigurationService $configuration,
        private readonly FinancialReconciliationProjection $reconciliation,
    ) {}

    public function handle(User $actor, DashboardPeriod $period): FinanceAnalyticsData
    {
        $organization = $this->authorization->authorizeView($actor);
        $organizationId = (int) $organization->getKey();

        try {
            $configuration = $this->configuration->configuration($organization);
            $validated = $this->integrity->inspect($configuration, $organizationId);

            if ($validated === null) {
                return FinanceAnalyticsData::unavailable();
            }

            $baseCurrency = $validated['base'];
            $obligationTable = (new FinancialObligation)->getTable();
            $ledgerTable = (new FinancialLedgerEntry)->getTable();

            if ($this->reconciliation->hasInvalidReconciliation(
                FinancialObligation::query()->where('organization_id', $organizationId),
            ) || $this->hasBaseCurrencyMismatch($organizationId, $baseCurrency->value, $obligationTable, $ledgerTable)) {
                return FinanceAnalyticsData::unavailable();
            }

            $revenue = $this->baseAmountSum(
                DB::table($ledgerTable)
                    ->where('organization_id', $organizationId)
                    ->where('occurred_at', '>=', $period->startUtc)
                    ->where('occurred_at', '<', $period->endUtc),
            );
            $receipts = $this->receipts($organizationId, $period, $ledgerTable);
            $cohortClientCount = $this->cohortClientCount($organizationId, $period);
            $realizedLtv = $cohortClientCount === 0
                ? null
                : $this->averageMinor(
                    $this->cohortLedgerSum($organizationId, $period, $obligationTable, $ledgerTable),
                    $cohortClientCount,
                );
            $debt = $this->periodEndDebt($organizationId, $period, $baseCurrency->value, $obligationTable, $ledgerTable);

            return new FinanceAnalyticsData(
                available: true,
                baseCurrency: $baseCurrency->value,
                revenueMinor: $revenue,
                averageReceiptMinor: $receipts['count'] === 0 ? null : $this->averageMinor($receipts['total'], $receipts['count']),
                realizedLtvMinor: $realizedLtv,
                debtMinor: $debt,
                receiptCount: $receipts['count'],
                cohortClientCount: $cohortClientCount,
            );
        } catch (\Throwable) {
            return FinanceAnalyticsData::unavailable();
        }
    }

    /** @return array{total: string, count: int} */
    private function receipts(int $organizationId, DashboardPeriod $period, string $ledgerTable): array
    {
        $row = DB::table($ledgerTable)
            ->where('organization_id', $organizationId)
            ->where('occurred_at', '>=', $period->startUtc)
            ->where('occurred_at', '<', $period->endUtc)
            ->whereIn('entry_type', [
                FinancialLedgerEntryType::ManualPayment->value,
                FinancialLedgerEntryType::FakeGatewaySettlement->value,
            ])
            ->where('base_amount_minor', '>', 0)
            ->selectRaw('COALESCE(SUM(base_amount_minor), 0) as total')
            ->selectRaw('COUNT(*) as receipt_count')
            ->first();

        return [
            'total' => $this->integerString($row?->total),
            'count' => (int) ($row->receipt_count ?? 0),
        ];
    }

    private function cohortClientCount(int $organizationId, DashboardPeriod $period): int
    {
        $clientTable = (new Client)->getTable();

        return DB::table($clientTable)
            ->where('organization_id', $organizationId)
            ->where('created_at', '>=', $period->startUtc)
            ->where('created_at', '<', $period->endUtc)
            ->count();
    }

    private function cohortLedgerSum(int $organizationId, DashboardPeriod $period, string $obligationTable, string $ledgerTable): string
    {
        $clientTable = (new Client)->getTable();
        $cohort = DB::table($clientTable)
            ->where('organization_id', $organizationId)
            ->where('created_at', '>=', $period->startUtc)
            ->where('created_at', '<', $period->endUtc)
            ->select('id');

        $query = DB::table($ledgerTable.' as ledger')
            ->join($obligationTable.' as obligations', function (JoinClause $join): void {
                $join
                    ->on('obligations.organization_id', '=', 'ledger.organization_id')
                    ->on('obligations.id', '=', 'ledger.obligation_id');
            })
            ->where('ledger.organization_id', $organizationId)
            ->where('obligations.organization_id', $organizationId)
            ->whereIn('obligations.client_id', $cohort)
            ->where('ledger.occurred_at', '<=', $period->nowUtc);

        return $this->qualifiedBaseAmountSum($query);
    }

    private function periodEndDebt(int $organizationId, DashboardPeriod $period, string $baseCurrency, string $obligationTable, string $ledgerTable): string
    {
        $obligationTotal = $this->baseAmountSum(
            DB::table($obligationTable)
                ->where('organization_id', $organizationId)
                ->where('base_currency', $baseCurrency)
                ->where('created_at', '<', $period->endUtc),
        );
        $appliedTotal = $this->qualifiedBaseAmountSum(
            DB::table($ledgerTable.' as ledger')
                ->join($obligationTable.' as obligations', function (JoinClause $join): void {
                    $join
                        ->on('obligations.organization_id', '=', 'ledger.organization_id')
                        ->on('obligations.id', '=', 'ledger.obligation_id');
                })
                ->where('ledger.organization_id', $organizationId)
                ->where('obligations.organization_id', $organizationId)
                ->where('obligations.base_currency', $baseCurrency)
                ->where('obligations.created_at', '<', $period->endUtc)
                ->where('ledger.occurred_at', '<', $period->endUtc),
        );

        return BigInteger::of($obligationTotal)->minus($appliedTotal)->toString();
    }

    private function averageMinor(string $total, int $count): string
    {
        return BigDecimal::of($total)
            ->dividedBy($count, 0, RoundingMode::HalfUp)
            ->toBigInteger()
            ->toString();
    }

    private function baseAmountSum(Builder $query): string
    {
        $row = $query->selectRaw('COALESCE(SUM(base_amount_minor), 0) as total')->first();

        return $this->integerString($row?->total);
    }

    private function qualifiedBaseAmountSum(Builder $query): string
    {
        $row = $query->selectRaw('COALESCE(SUM(ledger.base_amount_minor), 0) as total')->first();

        return $this->integerString($row?->total);
    }

    private function hasBaseCurrencyMismatch(int $organizationId, string $baseCurrency, string $obligationTable, string $ledgerTable): bool
    {
        return DB::table($obligationTable)
            ->where('organization_id', $organizationId)
            ->where('base_currency', '<>', $baseCurrency)
            ->exists()
            || DB::table($ledgerTable)
                ->where('organization_id', $organizationId)
                ->where('base_currency', '<>', $baseCurrency)
                ->exists();
    }

    private function integerString(mixed $value): string
    {
        $value = is_int($value) ? (string) $value : $value;

        if (! is_string($value) || preg_match('/^-?(0|[1-9][0-9]*)$/', $value) !== 1) {
            throw new \UnexpectedValueException('A persisted financial aggregate is invalid.');
        }

        return BigInteger::of($value)->toString();
    }
}
