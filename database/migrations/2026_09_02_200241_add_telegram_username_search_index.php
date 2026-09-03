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
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement('CREATE INDEX client_channel_identities_telegram_username_ix ON client_channel_identities USING gin (external_username gin_trgm_ops) WHERE channel = \'telegram\' AND external_username IS NOT NULL');
        } else {
            Schema::table('client_channel_identities', function (Blueprint $table): void {
                $table->index(['organization_id', 'channel', 'external_username'], 'client_channel_identities_username_ix');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS client_channel_identities_telegram_username_ix');
        } else {
            Schema::table('client_channel_identities', function (Blueprint $table): void {
                $table->dropIndex('client_channel_identities_username_ix');
            });
        }
    }
};
