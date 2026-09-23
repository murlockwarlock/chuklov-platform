<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_obligations', function (Blueprint $table): void {
            $table->dropForeign(['organization_id', 'booking_id']);
            $table->dropForeign(['organization_id', 'service_id']);
            $table->unsignedBigInteger('booking_id')->nullable()->change();
            $table->unsignedBigInteger('service_id')->nullable()->change();
            $table->unsignedBigInteger('purchase_id')->nullable()->after('service_id');
            $table->unique(['organization_id', 'purchase_id']);
            $table->foreign(['organization_id', 'booking_id'], 'financial_obligations_booking_fk')
                ->references(['organization_id', 'id'])
                ->on('bookings')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'service_id'], 'financial_obligations_service_fk')
                ->references(['organization_id', 'id'])
                ->on('services')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'purchase_id'], 'financial_obligations_purchase_fk')
                ->references(['organization_id', 'id'])
                ->on('commerce_purchases')
                ->restrictOnDelete();
            $table->index(['organization_id', 'purchase_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE financial_obligations ADD CONSTRAINT financial_obligations_subject_check CHECK ((purchase_id IS NOT NULL AND booking_id IS NULL AND service_id IS NULL) OR (purchase_id IS NULL AND booking_id IS NOT NULL AND service_id IS NOT NULL))');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE financial_obligations DROP CONSTRAINT IF EXISTS financial_obligations_subject_check');
        }

        Schema::table('financial_obligations', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'purchase_id']);
            $table->dropForeign('financial_obligations_purchase_fk');
            $table->dropForeign('financial_obligations_booking_fk');
            $table->dropForeign('financial_obligations_service_fk');
            $table->dropUnique(['organization_id', 'purchase_id']);
            $table->dropColumn('purchase_id');
            $table->unsignedBigInteger('booking_id')->nullable(false)->change();
            $table->unsignedBigInteger('service_id')->nullable(false)->change();
            $table->foreign(['organization_id', 'booking_id'])
                ->references(['organization_id', 'id'])
                ->on('bookings')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'service_id'])
                ->references(['organization_id', 'id'])
                ->on('services')
                ->restrictOnDelete();
        });
    }
};
