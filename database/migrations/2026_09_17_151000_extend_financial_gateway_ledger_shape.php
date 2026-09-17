<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE financial_ledger_entries DROP CONSTRAINT IF EXISTS financial_ledger_entries_shape_check');
        DB::statement("ALTER TABLE financial_ledger_entries ADD CONSTRAINT financial_ledger_entries_shape_check CHECK ((entry_type = 'manual_payment' AND source = 'crm' AND amount_minor > 0 AND payment_amount_minor > 0 AND settlement_amount_minor > 0 AND corrects_ledger_entry_id IS NULL) OR (entry_type = 'fake_gateway_settlement' AND source = 'fake_gateway' AND amount_minor > 0 AND payment_amount_minor > 0 AND settlement_amount_minor > 0 AND corrects_ledger_entry_id IS NULL) OR (entry_type = 'gateway_settlement' AND source = 'payment_gateway' AND amount_minor > 0 AND payment_amount_minor > 0 AND settlement_amount_minor > 0 AND corrects_ledger_entry_id IS NULL) OR (entry_type = 'correction' AND source = 'crm' AND amount_minor < 0 AND payment_amount_minor < 0 AND settlement_amount_minor < 0 AND corrects_ledger_entry_id IS NOT NULL))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE financial_ledger_entries DROP CONSTRAINT IF EXISTS financial_ledger_entries_shape_check');
        DB::statement("ALTER TABLE financial_ledger_entries ADD CONSTRAINT financial_ledger_entries_shape_check CHECK ((entry_type = 'manual_payment' AND source = 'crm' AND amount_minor > 0 AND payment_amount_minor > 0 AND settlement_amount_minor > 0 AND corrects_ledger_entry_id IS NULL) OR (entry_type = 'fake_gateway_settlement' AND source = 'fake_gateway' AND amount_minor > 0 AND payment_amount_minor > 0 AND settlement_amount_minor > 0 AND corrects_ledger_entry_id IS NULL) OR (entry_type = 'correction' AND source = 'crm' AND amount_minor < 0 AND payment_amount_minor < 0 AND settlement_amount_minor < 0 AND corrects_ledger_entry_id IS NOT NULL))");
    }
};
