<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b2b_sales_calls', function (Blueprint $table) {
            $table->string('provider_recreate_correlation_key', 64)
                ->nullable()
                ->after('provider_recreate_meeting_id');
        });
    }

    public function down(): void
    {
        Schema::table('b2b_sales_calls', function (Blueprint $table) {
            $table->dropColumn('provider_recreate_correlation_key');
        });
    }
};
