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
        $query->where(new FinanceSqlCondition(
            $this->supportedCurrencySql($column),
        ));
    }

    private function unsupportedCurrencySql(string $column): string
    {
        $canonical = $this->contract->canonicalCurrencySql($column);

        return '('.$canonical.' IS NULL OR NOT '.$this->supportedCurrencySql($column).')';
    }

    private function currencyMismatchSql(string $left, string $right): string
    {
        $canonicalLeft = $this->contract->canonicalCurrencySql($left);
        $canonicalRight = $this->contract->canonicalCurrencySql($right);

        return $canonicalLeft.' IS NULL OR '.$canonicalRight.' IS NULL OR '.$canonicalLeft.' <> '.$canonicalRight;
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
        $path = $obligationTable.".conversion_snapshots->'{$role}'";
        $jsonField = static fn (string $name): string => "({$path}->'{$name}')";
        $field = static fn (string $name): string => "({$path}->>'{$name}')";
        $sourceCurrency = $field('source_currency');
        $targetCurrency = $field('target_currency');
        $sourceAmount = $field('source_amount_minor');
        $targetAmount = $field('target_amount_minor');
        $rateText = $field('rate');
        $rounding = $field('rounding_mode');
        $sourceScale = $field('source_scale');
        $targetScale = $field('target_scale');
        $sourceCurrencyType = 'json_typeof('.$jsonField('source_currency').')';
        $targetCurrencyType = 'json_typeof('.$jsonField('target_currency').')';
        $sourceAmountType = 'json_typeof('.$jsonField('source_amount_minor').')';
        $targetAmountType = 'json_typeof('.$jsonField('target_amount_minor').')';
        $rateType = 'json_typeof('.$jsonField('rate').')';
        $roundingType = 'json_typeof('.$jsonField('rounding_mode').')';
        $sourceScaleType = 'json_typeof('.$jsonField('source_scale').')';
        $targetScaleType = 'json_typeof('.$jsonField('target_scale').')';
        $minor = fn (string $value, string $type): string => "CASE WHEN {$type} = 'string' AND {$value} ~ '".$this->contract->snapshotMinorPattern()."' THEN ({$value})::numeric END";
        $scale = fn (string $value, string $type): string => "CASE WHEN {$type} = 'number' AND {$value} ~ '^(0|[1-9][0-9]*)$' THEN ({$value})::integer END";
        $rate = "CASE WHEN {$rateType} = 'string' AND {$rateText} ~ '".$this->contract->ratePattern()."' THEN ({$rateText})::numeric END";
        $sourceAmountMinor = $minor($sourceAmount, $sourceAmountType);
        $targetAmountMinor = $minor($targetAmount, $targetAmountType);
        $sourceScaleValue = $scale($sourceScale, $sourceScaleType);
        $targetScaleValue = $scale($targetScale, $targetScaleType);
        $settlementCurrency = $obligationTable.'.settlement_currency';
        $targetColumnCurrency = $obligationTable.'.'.$targetCurrencyColumn;
        $raw = '('.$obligationTable.'.settlement_amount_minor::numeric * '.$rate.' * '
            .$this->contract->minorScaleFactorSql($settlementCurrency, $targetColumnCurrency).')';
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

        return "json_typeof({$path}) = 'object'"
            ." AND {$sourceCurrencyType} = 'string'"
            ." AND {$targetCurrencyType} = 'string'"
            .' AND '.$this->supportedCurrencySql($sourceCurrency)
            .' AND '.$this->supportedCurrencySql($targetCurrency)
            .' AND '.$sourceCurrency.' = '.$settlementCurrency
            .' AND '.$targetCurrency.' = '.$targetColumnCurrency
            .' AND '.$sourceAmountMinor.' = '.$obligationTable.'.settlement_amount_minor'
            .' AND '.$targetAmountMinor.' = '.$obligationTable.'.'.$targetAmountColumn
            .' AND '.$sourceScaleValue.' = '.$this->contract->currencyScaleSql($sourceCurrency)
            .' AND '.$targetScaleValue.' = '.$this->contract->currencyScaleSql($targetCurrency)
            .' AND '.$rate.' > 0'
            ." AND {$roundingType} = 'string'"
            .' AND '.$rounding." IN ('down', 'half_up', 'half_even')"
            .' AND ('.$sourceCurrency.' <> '.$targetCurrency
            ." OR ({$rateText} = '1' AND {$sourceAmountMinor} = {$targetAmountMinor}))"
            .' AND '.$rounded.' = '.$obligationTable.'.'.$targetAmountColumn;
    }

    private function sqliteSnapshotSql(
        string $obligationTable,
        string $role,
        string $targetAmountColumn,
        string $targetCurrencyColumn,
    ): string {
        $path = '$.'.$role;
        $json = "(CASE WHEN json_valid({$obligationTable}.conversion_snapshots) THEN {$obligationTable}.conversion_snapshots END)";
        $field = static fn (string $name): string => "json_extract({$json}, '{$path}.{$name}')";
        $type = static fn (string $name): string => "json_type({$json}, '{$path}.{$name}')";
        $snapshotType = "json_type({$json}, '{$path}')";
        $sourceCurrency = $field('source_currency');
        $targetCurrency = $field('target_currency');
        $sourceAmount = $field('source_amount_minor');
        $targetAmount = $field('target_amount_minor');
        $rateValue = $field('rate');
        $rateText = 'CAST('.$rateValue.' AS TEXT)';
        $rounding = $field('rounding_mode');
        $sourceScale = $field('source_scale');
        $targetScale = $field('target_scale');
        $sourceCurrencyType = $type('source_currency');
        $targetCurrencyType = $type('target_currency');
        $sourceAmountType = $type('source_amount_minor');
        $targetAmountType = $type('target_amount_minor');
        $rateType = $type('rate');
        $roundingType = $type('rounding_mode');
        $sourceScaleType = $type('source_scale');
        $targetScaleType = $type('target_scale');
        $settlementCurrency = $obligationTable.'.settlement_currency';
        $targetColumnCurrency = $obligationTable.'.'.$targetCurrencyColumn;
        $rate = "CASE WHEN {$rateType} = 'text' AND ".$this->sqliteRateSql($rateText)
            .' THEN '.$rateText.' END';
        $raw = '(CAST('.$obligationTable.'.settlement_amount_minor AS REAL) * '.$rate.' * '
            .$this->contract->minorScaleFactorSql($settlementCurrency, $targetColumnCurrency).')';
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

        return $snapshotType." = 'object'"
            ." AND {$sourceCurrencyType} = 'text'"
            ." AND {$targetCurrencyType} = 'text'"
            .' AND '.$this->supportedCurrencySql($sourceCurrency)
            .' AND '.$this->supportedCurrencySql($targetCurrency)
            .' AND '.$sourceCurrency.' = '.$settlementCurrency
            .' AND '.$targetCurrency.' = '.$targetColumnCurrency
            .' AND '.$this->sqliteMinorSql($sourceAmount, $obligationTable.'.settlement_amount_minor', $sourceAmountType)
            .' AND '.$this->sqliteMinorSql($targetAmount, $obligationTable.'.'.$targetAmountColumn, $targetAmountType)
            ." AND {$sourceScaleType} = 'integer'"
            .' AND '.$sourceScale.' = '.$this->contract->currencyScaleSql($sourceCurrency)
            ." AND {$targetScaleType} = 'integer'"
            .' AND '.$targetScale.' = '.$this->contract->currencyScaleSql($targetCurrency)
            .' AND '.$rate.' > 0'
            ." AND {$roundingType} = 'text'"
            .' AND '.$rounding." IN ('down', 'half_up', 'half_even')"
            .' AND ('.$sourceCurrency.' <> '.$targetCurrency
            ." OR ({$rateText} = '1' AND {$sourceAmount} = {$targetAmount}))"
            .' AND '.$rounded.' = '.$obligationTable.'.'.$targetAmountColumn;
    }

    private function sqliteMinorSql(string $value, string $expected, string $type): string
    {
        $text = 'CAST('.$value.' AS TEXT)';

        return $type." = 'text'"
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
        $canonicalFraction = "instr({$value}, '.') = 0 OR substr({$value}, -1, 1) <> '0'";

        return '(('.$noDecimal.' OR '.$decimal.') AND '.$canonicalFraction
            .' AND length(ltrim('.$value.", '0.')) > 0)";
    }

    private function supportedCurrencySql(string $column): string
    {
        $values = implode(', ', array_map(static fn (string $value): string => "'".$value."'", $this->contract->currencyValues()));

        return $this->contract->canonicalCurrencySql($column).' IN ('.$values.')';
    }
}
