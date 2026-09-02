<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_channel_identities', function (Blueprint $table): void {
            $table->string('external_username', 32)->nullable()->after('external_id');
        });
    }

    public function down(): void
    {
        Schema::table('client_channel_identities', function (Blueprint $table): void {
            $table->dropColumn('external_username');
        });
    }
};
