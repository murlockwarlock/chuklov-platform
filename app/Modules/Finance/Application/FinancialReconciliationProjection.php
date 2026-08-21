<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class FinancialReconciliationProjection
{
    private const OBLIGATION_TABLE = 'financial_obligations';

    private const LEDGER_TABLE = 'financial_ledger_entries';

    public function __construct(private readonly FinancialReconciliationContract $contract) {}

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
            ->where(new FinanceSqlCondition($this->invalidOrIncompatibleLedgerSql($obligationTable)));
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

        foreach ($this->contract->obligationCurrencyAttributes() as $attribute) {
            $this->whereSupportedCurrency($query, $obligationTable.'.'.$attribute);
        }

        foreach (array_merge(
            $this->contract->obligationAmountInvariants()['positive'],
            $this->contract->obligationAmountInvariants()['non_negative'],
        ) as $attribute) {
            $query->where(new FinanceSqlCondition(
                $this->contract->validIntegerSql($obligationTable.'.'.$attribute),
            ));
        }

        foreach ($this->contract->obligationAmountInvariants()['positive'] as $attribute) {
            $query->where($obligationTable.'.'.$attribute, '>', 0);
        }

        foreach ($this->contract->obligationAmountInvariants()['non_negative'] as $attribute) {
            $query->where($obligationTable.'.'.$attribute, '>=', 0);
        }

        $query
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
            .' AND '.$this->invalidOrIncompatibleLedgerSql($obligationTable, 'ledger')
            .')';
    }

    private function invalidOrIncompatibleLedgerSql(
        string $obligationTable,
        string $ledgerAlias = 'ledger',
    ): string {
        $invalidCurrencies = array_map(
            fn (string $attribute): string => $this->unsupportedCurrencySql($ledgerAlias.'.'.$attribute),
            $this->contract->ledgerCurrencyAttributes(),
        );
        $ledgerSettlement = $ledgerAlias.'.settlement_amount_minor';
        $currencyMismatches = array_map(
            fn (string $attribute): string => $this->currencyMismatchSql(
                $ledgerAlias.'.'.$attribute,
                $obligationTable.'.'.$attribute,
            ),
            ['settlement_currency', 'base_currency', 'display_currency'],
        );

        return '('.$ledgerSettlement.' IS NULL'
            .' OR NOT COALESCE('.$this->contract->validIntegerSql($ledgerSettlement).', false)'
            .' OR '.$ledgerSettlement.' = 0'
            .' OR ('.implode(' OR ', $invalidCurrencies).')'
            .' OR ('.implode(' OR ', $currencyMismatches).'))';
    }

    /** @param Builder<FinancialObligation> $query */
    private function whereSupportedCurrency(Builder $query, string $column): void
    {
        $values = implode(', ', array_map(static fn (string $value): string => "'".$value."'", $this->contract->currencyValues()));
        $query->where(new FinanceSqlCondition(
            $this->contract->normalizedCurrencySql($column).' IN ('.$values.')',
        ));
    }

    private function unsupportedCurrencySql(string $column): string
    {
        $values = implode(', ', array_map(static fn (string $value): string => "'".$value."'", $this->contract->currencyValues()));
        $normalized = $this->contract->normalizedCurrencySql($column);

        return '('.$normalized.' IS NULL OR '.$normalized.' NOT IN ('.$values.'))';
    }

    private function currencyMismatchSql(string $left, string $right): string
    {
        $normalizedLeft = $this->contract->normalizedCurrencySql($left);
        $normalizedRight = $this->contract->normalizedCurrencySql($right);

        return $normalizedLeft.' IS NULL OR '.$normalizedRight.' IS NULL OR '.$normalizedLeft.' <> '.$normalizedRight;
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
        $rateType = "json_typeof({$path}->'rate')";
        $rate = 'CASE'
            ." WHEN {$rateType} = 'string' AND {$rateText} ~ '".$this->contract->ratePattern()."' THEN ({$rateText})::numeric"
            ." WHEN {$rateType} = 'number' AND {$rateText} ~ '".$this->contract->integerRatePattern()."' THEN ({$rateText})::numeric"
            .' END';
        $normalizedSourceCurrency = $this->contract->normalizedCurrencySql($sourceCurrency);
        $normalizedTargetCurrency = $this->contract->normalizedCurrencySql($targetCurrency);
        $normalizedSettlementCurrency = $this->contract->normalizedCurrencySql($obligationTable.'.settlement_currency');
        $normalizedTargetColumn = $this->contract->normalizedCurrencySql($obligationTable.'.'.$targetCurrencyColumn);
        $raw = '('.$obligationTable.'.settlement_amount_minor::numeric * '.$rate.' * CASE'
            .' WHEN '.$normalizedSettlementCurrency." = 'JPY' AND ".$normalizedTargetColumn." <> 'JPY' THEN 100"
            .' WHEN '.$normalizedSettlementCurrency." <> 'JPY' AND ".$normalizedTargetColumn." = 'JPY' THEN 0.01"
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
            .' AND '.$normalizedSourceCurrency.' = '.$normalizedSettlementCurrency
            .' AND '.$normalizedTargetCurrency.' = '.$normalizedTargetColumn
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
        $sourceAmountType = "json_type({$obligationTable}.conversion_snapshots, '{$path}.source_amount_minor')";
        $targetAmountType = "json_type({$obligationTable}.conversion_snapshots, '{$path}.target_amount_minor')";
        $rateValue = $field('rate');
        $rateType = "json_type({$obligationTable}.conversion_snapshots, '{$path}.rate')";
        $rateText = 'CAST('.$rateValue.' AS TEXT)';
        $rounding = $field('rounding_mode');
        $normalizedSourceCurrency = $this->contract->normalizedCurrencySql('CAST('.$sourceCurrency.' AS TEXT)');
        $normalizedTargetCurrency = $this->contract->normalizedCurrencySql('CAST('.$targetCurrency.' AS TEXT)');
        $normalizedSettlementCurrency = $this->contract->normalizedCurrencySql($obligationTable.'.settlement_currency');
        $normalizedTargetColumn = $this->contract->normalizedCurrencySql($obligationTable.'.'.$targetCurrencyColumn);
        $rate = "CASE WHEN {$rateType} IN ('text', 'integer') AND ".$this->sqliteRateSql($rateText)
            .' THEN '.$rateText.' END';
        $raw = '(CAST('.$obligationTable.'.settlement_amount_minor AS REAL) * '.$rate.' * CASE'
            .' WHEN '.$normalizedSettlementCurrency." = 'JPY' AND ".$normalizedTargetColumn." <> 'JPY' THEN 100"
            .' WHEN '.$normalizedSettlementCurrency." <> 'JPY' AND ".$normalizedTargetColumn." = 'JPY' THEN 0.01"
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
            .' AND '.$normalizedSourceCurrency.' = '.$normalizedSettlementCurrency
            .' AND '.$normalizedTargetCurrency.' = '.$normalizedTargetColumn
            .' AND '.$this->sqliteMinorSql($sourceAmount, $obligationTable.'.settlement_amount_minor', $sourceAmountType)
            .' AND '.$this->sqliteMinorSql($targetAmount, $obligationTable.'.'.$targetAmountColumn, $targetAmountType)
            .' AND '.$rate.' > 0'
            .' AND '.$rounding." IN ('down', 'half_up', 'half_even')"
            .' AND '.$rounded.' = '.$obligationTable.'.'.$targetAmountColumn;
    }

    private function sqliteMinorSql(string $value, string $expected, string $type): string
    {
        $text = 'CAST('.$value.' AS TEXT)';

        return $type." IN ('text', 'integer')"
            .' AND '.$value.' IS NOT NULL'
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
