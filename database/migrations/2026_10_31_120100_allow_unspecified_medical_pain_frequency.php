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

        DB::statement('ALTER TABLE medical_profile_pains DROP CONSTRAINT IF EXISTS medical_profile_pains_frequency_check');
        DB::statement("ALTER TABLE medical_profile_pains ADD CONSTRAINT medical_profile_pains_frequency_check CHECK ((frequency_mode = 'unspecified' AND frequency_count IS NULL AND frequency_period_unit IS NULL) OR (frequency_mode = 'permanent' AND frequency_count IS NULL AND frequency_period_unit IS NULL) OR (frequency_mode = 'count' AND frequency_count IS NOT NULL AND frequency_count > 0 AND frequency_period_unit IS NOT NULL))");
        DB::statement('ALTER TABLE medical_session_pains DROP CONSTRAINT IF EXISTS medical_session_pains_frequency_check');
        DB::statement("ALTER TABLE medical_session_pains ADD CONSTRAINT medical_session_pains_frequency_check CHECK ((frequency_mode = 'unspecified' AND frequency_count IS NULL AND frequency_period_unit IS NULL) OR (frequency_mode = 'permanent' AND frequency_count IS NULL AND frequency_period_unit IS NULL) OR (frequency_mode = 'count' AND frequency_count IS NOT NULL AND frequency_count > 0 AND frequency_period_unit IS NOT NULL))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE medical_profile_pains DROP CONSTRAINT IF EXISTS medical_profile_pains_frequency_check');
        DB::statement("ALTER TABLE medical_profile_pains ADD CONSTRAINT medical_profile_pains_frequency_check CHECK ((frequency_mode = 'permanent' AND frequency_count IS NULL AND frequency_period_unit IS NULL) OR (frequency_mode = 'count' AND frequency_count IS NOT NULL AND frequency_count > 0 AND frequency_period_unit IS NOT NULL))");
        DB::statement('ALTER TABLE medical_session_pains DROP CONSTRAINT IF EXISTS medical_session_pains_frequency_check');
        DB::statement("ALTER TABLE medical_session_pains ADD CONSTRAINT medical_session_pains_frequency_check CHECK ((frequency_mode = 'permanent' AND frequency_count IS NULL AND frequency_period_unit IS NULL) OR (frequency_mode = 'count' AND frequency_count IS NOT NULL AND frequency_count > 0 AND frequency_period_unit IS NOT NULL))");
    }
};
