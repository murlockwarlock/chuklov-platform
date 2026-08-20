<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class FinancialReconciliationProjection
{
    private const OBLIGATION_TABLE = 'financial_obligations';

    private const LEDGER_TABLE = 'financial_ledger_entries';

    /** @return Builder<FinancialLedgerEntry> */
    public function appliedSettlementQuery(string $obligationTable, string $ledgerTable): Builder
    {
        return FinancialLedgerEntry::query()
            ->from($ledgerTable.' as ledger')
            ->selectRaw('COALESCE(SUM(ledger.settlement_amount_minor), 0)')
            ->whereColumn('ledger.organization_id', $obligationTable.'.organization_id')
            ->whereColumn('ledger.obligation_id', $obligationTable.'.id');
    }

    /** @return Builder<FinancialLedgerEntry> */
    public function incompatibleLedgerRowsQuery(string $obligationTable, string $ledgerTable): Builder
    {
        return FinancialLedgerEntry::query()
            ->from($ledgerTable.' as ledger')
            ->selectRaw('COUNT(*)')
            ->whereColumn('ledger.organization_id', $obligationTable.'.organization_id')
            ->whereColumn('ledger.obligation_id', $obligationTable.'.id')
            ->where(function (Builder $query) use ($obligationTable): void {
                $query
                    ->whereColumn('ledger.settlement_currency', '<>', $obligationTable.'.settlement_currency')
                    ->orWhereColumn('ledger.base_currency', '<>', $obligationTable.'.base_currency')
                    ->orWhereColumn('ledger.display_currency', '<>', $obligationTable.'.display_currency');
            });
    }

    /**
     * @param  Builder<FinancialObligation>  $query
     * @return Builder<FinancialObligation>
     */
    public function applyValidReconciliationFilter(Builder $query): Builder
    {
        $obligationTable = self::OBLIGATION_TABLE;
        $ledgerTable = self::LEDGER_TABLE;
        $applied = $this->appliedSettlementSql($obligationTable, $ledgerTable);

        $query
            ->whereIn($obligationTable.'.currency', $this->currencyValues())
            ->whereIn($obligationTable.'.base_currency', $this->currencyValues())
            ->whereIn($obligationTable.'.display_currency', $this->currencyValues())
            ->whereIn($obligationTable.'.payment_currency', $this->currencyValues())
            ->whereIn($obligationTable.'.settlement_currency', $this->currencyValues())
            ->where($obligationTable.'.settlement_amount_minor', '>', 0)
            ->where($obligationTable.'.base_amount_minor', '>=', 0)
            ->where($obligationTable.'.display_amount_minor', '>=', 0)
            ->where(new FinanceSqlCondition($applied.' >= 0'))
            ->where(new FinanceSqlCondition($applied.' <= '.$obligationTable.'.settlement_amount_minor'))
            ->where(new FinanceSqlCondition($this->compatibleLedgerSql($obligationTable, $ledgerTable)))
            ->where(new FinanceSqlCondition($this->snapshotSql(
                $obligationTable,
                'base',
                'base_amount_minor',
                'base_currency',
            )))
            ->where(new FinanceSqlCondition($this->snapshotSql(
                $obligationTable,
                'display',
                'display_amount_minor',
                'display_currency',
            )));

        return $query;
    }

    /**
     * @param  Builder<FinancialObligation>  $query
     */
    public function hasInvalidReconciliation(Builder $query): bool
    {
        $totalQuery = clone $query;
        $validQuery = clone $query;
        $table = $query->getModel()->getTable();

        $total = $totalQuery
            ->reorder()
            ->select($table.'.id')
            ->count();
        $valid = $this->applyValidReconciliationFilter($validQuery)
            ->reorder()
            ->select($table.'.id')
            ->count();

        return $valid < $total;
    }

    /** @return list<string> */
    private function currencyValues(): array
    {
        return array_map(
            static fn (CurrencyCode $currency): string => $currency->value,
            CurrencyCode::cases(),
        );
    }

    private function appliedSettlementSql(string $obligationTable, string $ledgerTable): string
    {
        return '(SELECT COALESCE(SUM(ledger.settlement_amount_minor), 0)'
            .' FROM '.$ledgerTable.' AS ledger'
            .' WHERE ledger.organization_id = '.$obligationTable.'.organization_id'
            .' AND ledger.obligation_id = '.$obligationTable.'.id)';
    }

    private function compatibleLedgerSql(string $obligationTable, string $ledgerTable): string
    {
        return 'NOT EXISTS ('
            .'SELECT 1 FROM '.$ledgerTable.' AS ledger'
            .' WHERE ledger.organization_id = '.$obligationTable.'.organization_id'
            .' AND ledger.obligation_id = '.$obligationTable.'.id'
            .' AND ('
            .'ledger.settlement_currency <> '.$obligationTable.'.settlement_currency'
            .' OR ledger.base_currency <> '.$obligationTable.'.base_currency'
            .' OR ledger.display_currency <> '.$obligationTable.'.display_currency'
            .')'
            .')';
    }

    private function snapshotSql(
        string $obligationTable,
        string $role,
        string $targetAmountColumn,
        string $targetCurrencyColumn,
    ): string {
        return DB::getDriverName() === 'pgsql'
            ? $this->postgresSnapshotSql($obligationTable, $role, $targetAmountColumn, $targetCurrencyColumn)
            : $this->sqliteSnapshotSql($obligationTable, $role, $targetAmountColumn, $targetCurrencyColumn);
    }

    private function postgresSnapshotSql(
        string $obligationTable,
        string $role,
        string $targetAmountColumn,
        string $targetCurrencyColumn,
    ): string {
        $path = $obligationTable.'.conversion_snapshots->\''.$role.'\'';
        $field = static fn (string $name): string => '('.$path.'->>\''.$name.'\')';
        $sourceCurrency = $field('source_currency');
        $targetCurrency = $field('target_currency');
        $sourceAmount = $field('source_amount_minor');
        $targetAmount = $field('target_amount_minor');
        $rateText = $field('rate');
        $rounding = $field('rounding_mode');
        $minor = static fn (string $value): string => "CASE WHEN {$value} ~ '^(0|[1-9][0-9]*)$' THEN ({$value})::numeric END";
        $rate = "CASE WHEN {$rateText} ~ '^(?:0|[1-9][0-9]{0,19})(?:\\.[0-9]{1,18})?$' THEN ({$rateText})::numeric END";
        $raw = '('.$obligationTable.'.settlement_amount_minor::numeric * '.$rate.' * CASE'
            .' WHEN '.$obligationTable.'.settlement_currency = \'JPY\' AND '.$obligationTable.'.'.$targetCurrencyColumn." <> 'JPY' THEN 100"
            .' WHEN '.$obligationTable.'.settlement_currency <> \'JPY\' AND '.$obligationTable.'.'.$targetCurrencyColumn." = 'JPY' THEN 0.01"
            .' ELSE 1 END)';
        $floor = 'floor('.$raw.')';
        $rounded = "CASE\n"
            ." WHEN {$rounding} = 'down' THEN {$floor}\n"
            ." WHEN {$rounding} = 'half_up' THEN floor({$raw} + 0.5)\n"
            ." WHEN {$rounding} = 'half_even' THEN {$floor} + CASE\n"
            ."   WHEN ({$raw} - {$floor}) < 0.5 THEN 0\n"
            ."   WHEN ({$raw} - {$floor}) > 0.5 THEN 1\n"
            ."   WHEN mod({$floor}, 2) = 0 THEN 0\n"
            ."   ELSE 1\n"
            .' END END';

        return "json_typeof({$obligationTable}.conversion_snapshots->'{$role}') = 'object'"
            .' AND '.$sourceCurrency.' = '.$obligationTable.'.settlement_currency'
            .' AND '.$targetCurrency.' = '.$obligationTable.'.'.$targetCurrencyColumn
            .' AND '.$minor($sourceAmount).' = '.$obligationTable.'.settlement_amount_minor'
            .' AND '.$minor($targetAmount).' = '.$obligationTable.'.'.$targetAmountColumn
            .' AND '.$rate.' > 0'
            .' AND '.$rounding." IN ('down', 'half_up', 'half_even')"
            .' AND '.$rounded.' = '.$obligationTable.'.'.$targetAmountColumn;
    }

    private function sqliteSnapshotSql(
        string $obligationTable,
        string $role,
        string $targetAmountColumn,
        string $targetCurrencyColumn,
    ): string {
        $path = '$.'.$role;
        $field = static fn (string $name): string => "json_extract({$obligationTable}.conversion_snapshots, '{$path}.{$name}')";
        $sourceCurrency = $field('source_currency');
        $targetCurrency = $field('target_currency');
        $sourceAmount = $field('source_amount_minor');
        $targetAmount = $field('target_amount_minor');
        $rateValue = $field('rate');
        $rateText = 'CAST('.$rateValue.' AS TEXT)';
        $rounding = $field('rounding_mode');
        $raw = '(CAST('.$obligationTable.'.settlement_amount_minor AS REAL) * CAST('.$rateText.' AS REAL) * CASE'
            .' WHEN '.$obligationTable.'.settlement_currency = \'JPY\' AND '.$obligationTable.'.'.$targetCurrencyColumn." <> 'JPY' THEN 100"
            .' WHEN '.$obligationTable.'.settlement_currency <> \'JPY\' AND '.$obligationTable.'.'.$targetCurrencyColumn." = 'JPY' THEN 0.01"
            .' ELSE 1 END)';
        $floor = 'CAST('.$raw.' AS INTEGER)';
        $rounded = "CASE\n"
            ." WHEN {$rounding} = 'down' THEN {$floor}\n"
            ." WHEN {$rounding} = 'half_up' THEN CAST(({$raw} + 0.5) AS INTEGER)\n"
            ." WHEN {$rounding} = 'half_even' THEN {$floor} + CASE\n"
            ."   WHEN ({$raw} - {$floor}) < 0.5 THEN 0\n"
            ."   WHEN ({$raw} - {$floor}) > 0.5 THEN 1\n"
            ."   WHEN ({$floor} % 2) = 0 THEN 0\n"
            ."   ELSE 1\n"
            .' END END';

        return "json_type({$obligationTable}.conversion_snapshots, '{$path}') = 'object'"
            .' AND CAST('.$sourceCurrency.' AS TEXT) = '.$obligationTable.'.settlement_currency'
            .' AND CAST('.$targetCurrency.' AS TEXT) = '.$obligationTable.'.'.$targetCurrencyColumn
            .' AND '.$this->sqliteMinorSql($sourceAmount, $obligationTable.'.settlement_amount_minor')
            .' AND '.$this->sqliteMinorSql($targetAmount, $obligationTable.'.'.$targetAmountColumn)
            .' AND '.$this->sqliteRateSql($rateText)
            .' AND '.$rounding." IN ('down', 'half_up', 'half_even')"
            .' AND '.$rounded.' = '.$obligationTable.'.'.$targetAmountColumn;
    }

    private function sqliteMinorSql(string $value, string $expected): string
    {
        $text = 'CAST('.$value.' AS TEXT)';

        return $value.' IS NOT NULL'
            .' AND '.$text." NOT GLOB '*[^0-9]*'"
            .' AND '.$text." <> ''"
            .' AND ('.$text." = '0' OR substr(".$text.', 1, 1) <> \'0\')'
            .' AND '.$text.' = CAST('.$expected.' AS TEXT)';
    }

    private function sqliteRateSql(string $value): string
    {
        $integer = "CASE WHEN instr({$value}, '.') > 0 THEN substr({$value}, 1, instr({$value}, '.') - 1) ELSE {$value} END";
        $fraction = "substr({$value}, instr({$value}, '.') + 1)";
        $integerValid = '('.$integer." <> '' AND {$integer} NOT GLOB '*[^0-9]*'"
            .' AND length('.$integer.') BETWEEN 1 AND 20'
            .' AND ('.$integer." = '0' OR substr(".$integer.', 1, 1) <> \'0\'))';
        $fractionValid = '('.$fraction." <> '' AND {$fraction} NOT GLOB '*[^0-9]*'"
            .' AND length('.$fraction.') BETWEEN 1 AND 18)';
        $noDecimal = "instr({$value}, '.') = 0 AND {$integerValid}";
        $decimal = "instr({$value}, '.') > 0 AND {$integerValid} AND {$fractionValid}";

        return '(('.$noDecimal.' OR '.$decimal.') AND CAST('.$value.' AS REAL) > 0)';
    }
}
