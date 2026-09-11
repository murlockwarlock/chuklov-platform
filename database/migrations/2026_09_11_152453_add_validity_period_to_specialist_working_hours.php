<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('specialist_working_hours', function (Blueprint $table): void {
            $table->date('starts_on')->nullable()->after('weekday');
            $table->date('ends_on')->nullable()->after('starts_on');
            $table->index(
                ['organization_id', 'specialist_id', 'weekday', 'is_active', 'starts_on', 'ends_on'],
                'specialist_working_hours_validity_ix',
            );
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE specialist_working_hours DROP CONSTRAINT IF EXISTS specialist_working_hours_no_overlap');
        DB::statement(
            'ALTER TABLE specialist_working_hours ADD CONSTRAINT specialist_working_hours_valid_period '
            .'CHECK (starts_on IS NULL OR ends_on IS NULL OR starts_on <= ends_on)'
        );
        DB::statement(
            'ALTER TABLE specialist_working_hours ADD CONSTRAINT specialist_working_hours_no_overlap '
            .'EXCLUDE USING gist ('
            .'organization_id WITH =, specialist_id WITH =, weekday WITH =, '
            ."daterange(COALESCE(starts_on, '-infinity'::date), COALESCE(ends_on, 'infinity'::date), '[]') WITH &&, "
            .'int4range((extract(hour from start_time) * 60 + extract(minute from start_time))::integer, '
            ."(extract(hour from end_time) * 60 + extract(minute from end_time))::integer, '[)') WITH &&"
            .') WHERE (is_active)'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE specialist_working_hours DROP CONSTRAINT IF EXISTS specialist_working_hours_no_overlap');
            DB::statement('ALTER TABLE specialist_working_hours DROP CONSTRAINT IF EXISTS specialist_working_hours_valid_period');
            DB::statement(
                'ALTER TABLE specialist_working_hours ADD CONSTRAINT specialist_working_hours_no_overlap '
                .'EXCLUDE USING gist ('
                .'organization_id WITH =, specialist_id WITH =, weekday WITH =, '
                .'int4range((extract(hour from start_time) * 60 + extract(minute from start_time))::integer, '
                ."(extract(hour from end_time) * 60 + extract(minute from end_time))::integer, '[)') WITH &&"
                .') WHERE (is_active)'
            );
        }

        Schema::table('specialist_working_hours', function (Blueprint $table): void {
            $table->dropIndex('specialist_working_hours_validity_ix');
            $table->dropColumn(['starts_on', 'ends_on']);
        });
    }
};
