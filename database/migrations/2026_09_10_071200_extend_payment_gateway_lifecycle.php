<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_gateway_transactions', function (Blueprint $table): void {
            $table->foreignId('refund_ledger_entry_id')->nullable()->after('ledger_entry_id');
            $table->timestampTz('refunded_at')->nullable()->after('settled_at');
            $table->index(['organization_id', 'gateway', 'status'], 'payment_gateway_transactions_gateway_status_index');
            $table->foreign(['organization_id', 'refund_ledger_entry_id'], 'payment_gateway_transactions_refund_entry_fk')
                ->references(['organization_id', 'id'])
                ->on('financial_ledger_entries')
                ->nullOnDelete();
        });

        Schema::table('payment_gateway_events', function (Blueprint $table): void {
            $table->string('event_type', 32)->default('settlement')->after('gateway');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payment_gateway_transactions ADD CONSTRAINT payment_gateway_transactions_status_check CHECK (status IN ('pending', 'failed', 'settled', 'refunded'))");
            DB::statement("ALTER TABLE payment_gateway_events ADD CONSTRAINT payment_gateway_events_type_check CHECK (event_type IN ('settlement', 'failure', 'refund'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payment_gateway_events DROP CONSTRAINT IF EXISTS payment_gateway_events_type_check');
            DB::statement('ALTER TABLE payment_gateway_transactions DROP CONSTRAINT IF EXISTS payment_gateway_transactions_status_check');
        }

        Schema::table('payment_gateway_events', function (Blueprint $table): void {
            $table->dropColumn('event_type');
        });
        Schema::table('payment_gateway_transactions', function (Blueprint $table): void {
            $table->dropForeign('payment_gateway_transactions_refund_entry_fk');
            $table->dropIndex('payment_gateway_transactions_gateway_status_index');
            $table->dropColumn(['refund_ledger_entry_id', 'refunded_at']);
        });
    }
};
