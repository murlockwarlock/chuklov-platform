<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->string('status', 32)->default('pending_payment');
            $table->bigInteger('total_amount_minor');
            $table->char('currency', 3);
            $table->json('purchase_snapshot');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('refunded_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->index(['organization_id', 'status', 'created_at']);
        });

        Schema::create('commerce_purchase_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_id');
            $table->string('sellable_type', 180);
            $table->unsignedBigInteger('sellable_id');
            $table->unsignedInteger('quantity')->default(1);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->json('product_snapshot');
            $table->string('fulfillment_provider', 80)->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'purchase_id'])
                ->references(['organization_id', 'id'])
                ->on('commerce_purchases')
                ->restrictOnDelete();
            $table->index(['organization_id', 'sellable_type', 'sellable_id']);
            $table->index(['organization_id', 'purchase_id']);
        });

        Schema::create('commerce_purchase_fulfillments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_item_id');
            $table->string('provider_type', 80);
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('external_reference', 180)->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestampTz('fulfilled_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'purchase_item_id']);
            $table->foreign(['organization_id', 'purchase_item_id'])
                ->references(['organization_id', 'id'])
                ->on('commerce_purchase_items')
                ->restrictOnDelete();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('commerce_fulfillment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('fulfillment_id');
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'fulfillment_id'])
                ->references(['organization_id', 'id'])
                ->on('commerce_purchase_fulfillments')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'actor_user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->nullOnDelete();
            $table->index(['organization_id', 'fulfillment_id', 'created_at']);
        });

        Schema::create('payment_provider_offer_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('gateway', 40);
            $table->string('sellable_type', 180);
            $table->unsignedBigInteger('sellable_id');
            $table->char('currency', 3);
            $table->string('external_offer_id', 180);
            $table->boolean('is_active')->default(true);
            $table->json('provider_metadata')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'gateway', 'sellable_type', 'sellable_id', 'currency']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE commerce_purchases ADD CONSTRAINT commerce_purchases_status_check CHECK (status IN ('pending_payment', 'paid', 'refunded') AND total_amount_minor > 0 AND currency ~ '^[A-Z]{3}$')");
            DB::statement("ALTER TABLE commerce_purchase_items ADD CONSTRAINT commerce_purchase_items_values_check CHECK (quantity > 0 AND amount_minor > 0 AND currency ~ '^[A-Z]{3}$')");
            DB::statement("ALTER TABLE commerce_purchase_fulfillments ADD CONSTRAINT commerce_purchase_fulfillments_status_check CHECK (status IN ('pending', 'processing', 'fulfilled', 'failed') AND attempts >= 0)");
            DB::statement("ALTER TABLE payment_provider_offer_mappings ADD CONSTRAINT payment_provider_offer_mappings_values_check CHECK (gateway <> '' AND sellable_type <> '' AND sellable_id > 0 AND currency ~ '^[A-Z]{3}$' AND external_offer_id <> '')");
            DB::statement('CREATE UNIQUE INDEX payment_provider_offer_mappings_active_unique ON payment_provider_offer_mappings (organization_id, gateway, sellable_type, sellable_id, currency) WHERE is_active = true');
        } else {
            $prefix = Schema::getConnection()->getTablePrefix();
            DB::statement('CREATE UNIQUE INDEX payment_provider_offer_mappings_active_unique ON '.$prefix.'payment_provider_offer_mappings (organization_id, gateway, sellable_type, sellable_id, currency) WHERE is_active = 1');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payment_provider_offer_mappings_active_unique');
        Schema::dropIfExists('payment_provider_offer_mappings');
        Schema::dropIfExists('commerce_fulfillment_events');
        Schema::dropIfExists('commerce_purchase_fulfillments');
        Schema::dropIfExists('commerce_purchase_items');
        Schema::dropIfExists('commerce_purchases');
    }
};
