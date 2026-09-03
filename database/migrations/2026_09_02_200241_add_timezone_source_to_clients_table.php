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
            $table->string('timezone_source', 24)->default('organization')->after('timezone');
            $table->index(['organization_id', 'timezone_source'], 'clients_org_timezone_source_ix');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_timezone_source_ck CHECK (timezone_source IN ('organization','device','manual'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_timezone_source_ck');
        }

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropIndex('clients_org_timezone_source_ix');
            $table->dropColumn('timezone_source');
        });
    }
};
