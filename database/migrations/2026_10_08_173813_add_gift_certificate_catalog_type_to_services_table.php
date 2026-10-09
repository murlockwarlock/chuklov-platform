<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS services_catalog_type_check');
            DB::statement("ALTER TABLE services ADD CONSTRAINT services_catalog_type_check CHECK (catalog_type IN ('service', 'physical_product', 'online_product', 'gift_certificate'))");

            return;
        }

        Schema::table('services', function (Blueprint $table): void {
            $table->enum('catalog_type', ['service', 'physical_product', 'online_product', 'gift_certificate'])->default('service')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            if (DB::table('services')->where('catalog_type', 'gift_certificate')->exists()) {
                return;
            }

            DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS services_catalog_type_check');
            DB::statement("ALTER TABLE services ADD CONSTRAINT services_catalog_type_check CHECK (catalog_type IN ('service', 'physical_product', 'online_product'))");

            return;
        }

        Schema::table('services', function (Blueprint $table): void {
            $table->enum('catalog_type', ['service', 'physical_product', 'online_product'])->default('service')->change();
        });
    }
};
