<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b2b_sales_calls', function (Blueprint $table): void {
            $table->string('provider_account_id', 255)->nullable()->after('provider_name');
            $table->string('provider_host_user_id', 255)->nullable()->after('provider_account_id');
            $table->string('provider_recreate_account_id', 255)->nullable()->after('provider_host_user_id');
            $table->string('provider_recreate_host_user_id', 255)->nullable()->after('provider_recreate_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('b2b_sales_calls', function (Blueprint $table): void {
            $table->dropColumn([
                'provider_account_id',
                'provider_host_user_id',
                'provider_recreate_account_id',
                'provider_recreate_host_user_id',
            ]);
        });
    }
};
