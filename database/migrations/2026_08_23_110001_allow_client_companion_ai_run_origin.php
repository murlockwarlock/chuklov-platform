<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT IF EXISTS ai_runs_origin_check');
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_origin_check CHECK (origin IN ('user', 'system_scenario', 'playground', 'evaluation', 'client_portal', 'client_companion'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT IF EXISTS ai_runs_origin_check');
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_origin_check CHECK (origin IN ('user', 'system_scenario', 'playground', 'evaluation', 'client_portal'))");
    }
};
