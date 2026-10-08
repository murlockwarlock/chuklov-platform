<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_booking_restrictions', function (Blueprint $table) {
            $table->string('restriction_type', 32)->default('self_booking')->after('client_id');
        });

        DB::statement('DROP INDEX IF EXISTS client_booking_restrictions_one_active');
        DB::statement(
            'CREATE UNIQUE INDEX client_booking_restrictions_one_active '
            .'ON client_booking_restrictions (organization_id, client_id, restriction_type) '
            .'WHERE unblocked_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS client_booking_restrictions_one_active');
        Schema::table('client_booking_restrictions', function (Blueprint $table) {
            $table->dropColumn('restriction_type');
        });
        DB::statement(
            'CREATE UNIQUE INDEX client_booking_restrictions_one_active '
            .'ON client_booking_restrictions (organization_id, client_id) '
            .'WHERE unblocked_at IS NULL'
        );
    }
};
