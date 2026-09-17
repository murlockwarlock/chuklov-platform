<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->string('payment_requirement', 32)
                ->default('postpay')
                ->after('payment_policy');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE services ADD CONSTRAINT services_payment_requirement_check CHECK (payment_requirement IN ('postpay', 'prepay_full'))"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS services_payment_requirement_check');
        }

        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('payment_requirement');
        });
    }
};
