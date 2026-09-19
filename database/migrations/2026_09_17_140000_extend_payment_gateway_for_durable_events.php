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
            $table->string('provider_reference', 180)->nullable()->change();
            $table->string('checkout_url', 2048)->nullable()->after('provider_reference');
        });

        Schema::table('payment_gateway_events', function (Blueprint $table): void {
            $table->dropForeign(['organization_id', 'gateway_transaction_id']);
            $table->unsignedBigInteger('gateway_transaction_id')->nullable()->change();
            $table->string('provider_event_id', 180)->nullable()->change();
            $table->string('provider_reference', 180)->nullable()->change();
            $table->bigInteger('amount_minor')->nullable()->change();
            $table->char('currency', 3)->nullable()->change();
            $table->string('provider_event_key', 255)->nullable()->after('provider_event_id');
            $table->string('processing_status', 32)->default('processed')->after('verification_status');
            $table->unsignedInteger('attempt_count')->default(0)->after('processing_status');
            $table->timestampTz('next_attempt_at')->nullable()->after('processed_at');
            $table->timestampTz('lease_expires_at')->nullable()->after('next_attempt_at');
            $table->text('last_error')->nullable()->after('lease_expires_at');
            $table->string('reconciliation_reason', 180)->nullable()->after('last_error');
            $table->json('payload')->nullable()->after('payload_hash');
            $table->foreign(['organization_id', 'gateway_transaction_id'], 'payment_gateway_events_transaction_fk')
                ->references(['organization_id', 'id'])
                ->on('payment_gateway_transactions')
                ->restrictOnDelete();
            $table->index(
                ['organization_id', 'gateway', 'provider_reference', 'processing_status'],
                'payment_gateway_events_link_index',
            );
            $table->index(
                ['organization_id', 'gateway', 'processing_status', 'next_attempt_at'],
                'payment_gateway_events_reprocessor_index',
            );
        });

        foreach (DB::table('payment_gateway_events')->select(['id', 'gateway', 'event_type', 'provider_event_id'])->orderBy('id')->get() as $event) {
            DB::table('payment_gateway_events')
                ->where('id', $event->id)
                ->update([
                    'provider_event_key' => $event->gateway.':'.$event->event_type.':'.($event->provider_event_id ?? $event->id),
                ]);
        }

        Schema::table('payment_gateway_events', function (Blueprint $table): void {
            $table->string('provider_event_key', 255)->nullable(false)->change();
            $table->unique(
                ['organization_id', 'gateway', 'provider_event_key'],
                'payment_gateway_events_provider_event_key_unique',
            );
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payment_gateway_transactions DROP CONSTRAINT IF EXISTS payment_gateway_transactions_status_check');
            DB::statement('ALTER TABLE payment_gateway_events DROP CONSTRAINT IF EXISTS payment_gateway_events_type_check');
            DB::statement("ALTER TABLE payment_gateway_transactions ADD CONSTRAINT payment_gateway_transactions_status_check CHECK (status IN ('initiating', 'pending', 'failed', 'settled', 'refunded', 'unknown'))");
            DB::statement("ALTER TABLE payment_gateway_events ADD CONSTRAINT payment_gateway_events_type_check CHECK (event_type IN ('settlement', 'failure', 'refund', 'chargeback', 'unknown'))");
            DB::statement("ALTER TABLE payment_gateway_events ADD CONSTRAINT payment_gateway_events_processing_status_check CHECK (processing_status IN ('pending_link', 'processing', 'processed', 'rejected', 'reconciliation_required'))");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payment_gateway_events DROP CONSTRAINT IF EXISTS payment_gateway_events_processing_status_check');
            DB::statement('ALTER TABLE payment_gateway_events DROP CONSTRAINT IF EXISTS payment_gateway_events_type_check');
            DB::statement('ALTER TABLE payment_gateway_transactions DROP CONSTRAINT IF EXISTS payment_gateway_transactions_status_check');
        }

        Schema::table('payment_gateway_events', function (Blueprint $table): void {
            $table->dropUnique('payment_gateway_events_provider_event_key_unique');
            $table->dropIndex('payment_gateway_events_link_index');
            $table->dropIndex('payment_gateway_events_reprocessor_index');
            $table->dropForeign('payment_gateway_events_transaction_fk');
            $table->dropColumn([
                'provider_event_key',
                'processing_status',
                'attempt_count',
                'next_attempt_at',
                'lease_expires_at',
                'last_error',
                'reconciliation_reason',
                'payload',
            ]);
            $table->unsignedBigInteger('gateway_transaction_id')->nullable(false)->change();
            $table->string('provider_event_id', 180)->nullable(false)->change();
            $table->string('provider_reference', 180)->nullable(false)->change();
            $table->bigInteger('amount_minor')->nullable(false)->change();
            $table->char('currency', 3)->nullable(false)->change();
            $table->foreign(['organization_id', 'gateway_transaction_id'])
                ->references(['organization_id', 'id'])
                ->on('payment_gateway_transactions')
                ->restrictOnDelete();
        });

        Schema::table('payment_gateway_transactions', function (Blueprint $table): void {
            $table->dropColumn('checkout_url');
            $table->string('provider_reference', 180)->nullable(false)->change();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payment_gateway_transactions ADD CONSTRAINT payment_gateway_transactions_status_check CHECK (status IN ('pending', 'failed', 'settled', 'refunded'))");
            DB::statement("ALTER TABLE payment_gateway_events ADD CONSTRAINT payment_gateway_events_type_check CHECK (event_type IN ('settlement', 'failure', 'refund'))");
        }
    }
};
