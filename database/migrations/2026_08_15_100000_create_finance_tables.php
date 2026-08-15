<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_currency_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->char('base_currency', 3);
            $table->char('display_currency', 3);
            $table->boolean('force_single_currency')->default(false);
            $table->string('rounding_mode', 32)->default('half_up');
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique('organization_id');
        });

        Schema::create('organization_allowed_currencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->char('currency', 3);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'currency']);
        });

        Schema::create('organization_exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->char('source_currency', 3);
            $table->char('target_currency', 3);
            $table->decimal('rate', 38, 18);
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('effective_at')->useCurrent();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['organization_id', 'source_currency', 'target_currency']);
            $table->foreign(['organization_id', 'created_by_user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->nullOnDelete();
        });

        Schema::create('financial_obligations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('booking_id');
            $table->foreignId('service_id');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->bigInteger('base_amount_minor');
            $table->char('base_currency', 3);
            $table->bigInteger('display_amount_minor');
            $table->char('display_currency', 3);
            $table->bigInteger('payment_amount_minor');
            $table->char('payment_currency', 3);
            $table->bigInteger('settlement_amount_minor');
            $table->char('settlement_currency', 3);
            $table->json('price_snapshot');
            $table->json('conversion_snapshots');
            $table->string('creation_key', 180);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'booking_id']);
            $table->unique(['organization_id', 'creation_key']);
            $table->index(['organization_id', 'client_id']);
            $table->index(['organization_id', 'settlement_currency']);
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'booking_id'])
                ->references(['organization_id', 'id'])
                ->on('bookings')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'service_id'])
                ->references(['organization_id', 'id'])
                ->on('services')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'created_by_user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->nullOnDelete();
        });

        Schema::create('financial_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('obligation_id');
            $table->string('entry_type', 40);
            $table->string('source', 40);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->bigInteger('payment_amount_minor');
            $table->char('payment_currency', 3);
            $table->bigInteger('base_amount_minor');
            $table->char('base_currency', 3);
            $table->bigInteger('display_amount_minor');
            $table->char('display_currency', 3);
            $table->bigInteger('settlement_amount_minor');
            $table->char('settlement_currency', 3);
            $table->json('conversion_snapshot')->nullable();
            $table->string('payment_method', 40)->nullable();
            $table->timestampTz('occurred_at');
            $table->text('note')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider_reference', 180)->nullable();
            $table->string('idempotency_key', 180);
            $table->foreignId('corrects_ledger_entry_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'idempotency_key']);
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'provider_reference']);
            $table->index(['organization_id', 'obligation_id', 'occurred_at']);
            $table->foreign(['organization_id', 'obligation_id'])
                ->references(['organization_id', 'id'])
                ->on('financial_obligations')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'actor_user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->nullOnDelete();
            $table->foreign(['organization_id', 'corrects_ledger_entry_id'])
                ->references(['organization_id', 'id'])
                ->on('financial_ledger_entries')
                ->restrictOnDelete();
        });

        Schema::create('financial_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('ledger_entry_id');
            $table->string('disk', 64);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'ledger_entry_id']);
            $table->foreign(['organization_id', 'ledger_entry_id'])
                ->references(['organization_id', 'id'])
                ->on('financial_ledger_entries')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'uploaded_by_user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->nullOnDelete();
        });

        Schema::create('finance_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 180);
            $table->string('operation', 80);
            $table->string('subject_type', 180);
            $table->foreignId('subject_id')->nullable();
            $table->string('request_hash', 64);
            $table->string('result_type', 180)->nullable();
            $table->foreignId('result_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['organization_id', 'operation', 'subject_id']);
        });

        Schema::create('payment_gateway_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('obligation_id');
            $table->string('gateway', 40);
            $table->string('idempotency_key', 180);
            $table->string('request_hash', 64);
            $table->string('provider_reference', 180);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->bigInteger('settlement_amount_minor');
            $table->char('settlement_currency', 3);
            $table->string('status', 32);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ledger_entry_id')->nullable();
            $table->timestampTz('initiated_at')->useCurrent();
            $table->timestampTz('settled_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'idempotency_key']);
            $table->unique(['organization_id', 'gateway', 'provider_reference']);
            $table->index(['organization_id', 'obligation_id', 'status']);
            $table->foreign(['organization_id', 'obligation_id'])
                ->references(['organization_id', 'id'])
                ->on('financial_obligations')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'created_by_user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->nullOnDelete();
            $table->foreign(['organization_id', 'ledger_entry_id'])
                ->references(['organization_id', 'id'])
                ->on('financial_ledger_entries')
                ->nullOnDelete();
        });

        Schema::create('payment_gateway_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('gateway_transaction_id');
            $table->string('gateway', 40);
            $table->string('provider_event_id', 180);
            $table->string('provider_reference', 180);
            $table->string('verification_status', 32);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('payload_hash', 64);
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'gateway', 'provider_event_id']);
            $table->index(['organization_id', 'provider_reference']);
            $table->foreign(['organization_id', 'gateway_transaction_id'])
                ->references(['organization_id', 'id'])
                ->on('payment_gateway_transactions')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE organization_currency_configurations ADD CONSTRAINT organization_currency_configurations_currency_check CHECK (base_currency ~ '^[A-Z]{3}$' AND display_currency ~ '^[A-Z]{3}$')");
            DB::statement("ALTER TABLE organization_allowed_currencies ADD CONSTRAINT organization_allowed_currencies_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
            DB::statement("ALTER TABLE organization_exchange_rates ADD CONSTRAINT organization_exchange_rates_values_check CHECK (source_currency <> target_currency AND source_currency ~ '^[A-Z]{3}$' AND target_currency ~ '^[A-Z]{3}$' AND rate > 0)");
            DB::statement('ALTER TABLE financial_obligations ADD CONSTRAINT financial_obligations_amounts_check CHECK (amount_minor > 0 AND base_amount_minor >= 0 AND display_amount_minor >= 0 AND payment_amount_minor > 0 AND settlement_amount_minor > 0)');
            DB::statement('ALTER TABLE financial_ledger_entries ADD CONSTRAINT financial_ledger_entries_amounts_check CHECK (amount_minor <> 0 AND payment_amount_minor <> 0 AND settlement_amount_minor <> 0)');
            DB::statement("ALTER TABLE financial_ledger_entries ADD CONSTRAINT financial_ledger_entries_shape_check CHECK ((entry_type = 'manual_payment' AND source = 'crm' AND amount_minor > 0 AND payment_amount_minor > 0 AND settlement_amount_minor > 0 AND corrects_ledger_entry_id IS NULL) OR (entry_type = 'fake_gateway_settlement' AND source = 'fake_gateway' AND amount_minor > 0 AND payment_amount_minor > 0 AND settlement_amount_minor > 0 AND corrects_ledger_entry_id IS NULL) OR (entry_type = 'correction' AND source = 'crm' AND amount_minor < 0 AND payment_amount_minor < 0 AND settlement_amount_minor < 0 AND corrects_ledger_entry_id IS NOT NULL))");
            DB::statement("ALTER TABLE payment_gateway_events ADD CONSTRAINT payment_gateway_events_verification_check CHECK (verification_status IN ('verified', 'rejected'))");
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS prevent_financial_ledger_mutation()');
            DB::statement(<<<'SQL'
                CREATE FUNCTION prevent_financial_ledger_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'financial ledger entries are append-only';
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            DB::statement('CREATE TRIGGER financial_ledger_entries_immutable BEFORE UPDATE OR DELETE ON financial_ledger_entries FOR EACH ROW EXECUTE FUNCTION prevent_financial_ledger_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS financial_ledger_entries_immutable ON financial_ledger_entries');
            DB::statement('DROP FUNCTION IF EXISTS prevent_financial_ledger_mutation()');
        }

        Schema::dropIfExists('payment_gateway_events');
        Schema::dropIfExists('payment_gateway_transactions');
        Schema::dropIfExists('finance_idempotency_keys');
        Schema::dropIfExists('financial_receipts');
        Schema::dropIfExists('financial_ledger_entries');
        Schema::dropIfExists('financial_obligations');
        Schema::dropIfExists('organization_exchange_rates');
        Schema::dropIfExists('organization_allowed_currencies');
        Schema::dropIfExists('organization_currency_configurations');
    }
};
