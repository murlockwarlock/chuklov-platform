<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('phone_search_key', 15)->nullable()->after('phone');
            $table->index(
                ['organization_id', 'phone_search_key'],
                'clients_organization_phone_search_key_idx',
            );
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX clients_full_name_trgm_idx ON clients USING gin (full_name gin_trgm_ops)');
        DB::statement('CREATE INDEX clients_email_trgm_idx ON clients USING gin (email gin_trgm_ops)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS clients_full_name_trgm_idx');
            DB::statement('DROP INDEX IF EXISTS clients_email_trgm_idx');
        }

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropIndex('clients_organization_phone_search_key_idx');
            $table->dropColumn('phone_search_key');
        });
    }
};
