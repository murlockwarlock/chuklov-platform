<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_purchases', function (Blueprint $table): void {
            $table->string('checkout_idempotency_key', 180)->nullable()->after('currency');
            $table->char('checkout_request_hash', 64)->nullable()->after('checkout_idempotency_key');
        });

        foreach (DB::table('commerce_purchases')->select('id')->orderBy('id')->get() as $purchase) {
            DB::table('commerce_purchases')
                ->where('id', $purchase->id)
                ->update([
                    'checkout_idempotency_key' => 'legacy-commerce:'.$purchase->id,
                    'checkout_request_hash' => hash('sha256', 'legacy-commerce:'.$purchase->id),
                ]);
        }

        Schema::table('commerce_purchases', function (Blueprint $table): void {
            $table->unique(['organization_id', 'checkout_idempotency_key'], 'commerce_purchases_checkout_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('commerce_purchases', function (Blueprint $table): void {
            $table->dropUnique('commerce_purchases_checkout_idempotency_unique');
            $table->dropColumn(['checkout_idempotency_key', 'checkout_request_hash']);
        });
    }
};
