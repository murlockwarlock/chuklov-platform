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

        DB::statement(
            'ALTER TABLE b2b_sales_calls ALTER COLUMN starts_at TYPE TIMESTAMPTZ(6), ALTER COLUMN ends_at TYPE TIMESTAMPTZ(6)',
        );
        DB::statement(
            "ALTER TABLE b2b_sales_calls ADD CONSTRAINT b2b_sales_calls_exact_interval_ck CHECK (ends_at > starts_at AND starts_at = date_trunc('minute', starts_at) AND ends_at = date_trunc('minute', ends_at))",
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE b2b_sales_calls DROP CONSTRAINT IF EXISTS b2b_sales_calls_exact_interval_ck');
    }
};
