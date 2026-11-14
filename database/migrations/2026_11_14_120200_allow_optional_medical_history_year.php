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
            DB::statement('ALTER TABLE medical_profile_history_entries DROP CONSTRAINT IF EXISTS medical_profile_history_year_check');
        }

        Schema::table('medical_profile_history_entries', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->nullable()->change();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE medical_profile_history_entries ADD CONSTRAINT medical_profile_history_year_check CHECK (year IS NULL OR year BETWEEN 1800 AND 2200)');
        }
    }

    public function down(): void
    {
        throw new LogicException('Medical history year values are retained by forward-only migrations.');
    }
};
