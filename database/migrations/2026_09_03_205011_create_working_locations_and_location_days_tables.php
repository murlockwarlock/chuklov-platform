<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('working_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('address', 500);
            $table->string('timezone', 64);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('map_url', 2000)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default_office')->default(false);
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'is_active', 'is_default_office']);
        });

        Schema::create('location_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('area_name', 160);
            $table->unsignedSmallInteger('weekday')->nullable();
            $table->date('specific_date')->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->string('timezone', 64);
            $table->boolean('is_active')->default(true);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'area_name', 'is_active', 'weekday']);
            $table->index(['organization_id', 'specific_date', 'is_active']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE working_locations ADD CONSTRAINT working_locations_coordinates_ck '
            .'CHECK ((latitude IS NULL OR latitude BETWEEN -90 AND 90) AND '
            .'(longitude IS NULL OR longitude BETWEEN -180 AND 180) AND timezone <> \'\' AND '
            .'(NOT is_default_office OR is_active))'
        );
        DB::statement(
            'CREATE UNIQUE INDEX working_locations_one_default_office_ux '
            .'ON working_locations (organization_id) WHERE is_default_office'
        );
        DB::statement(
            'ALTER TABLE location_days ADD CONSTRAINT location_days_schedule_ck '
            .'CHECK ((weekday IS NOT NULL OR specific_date IS NOT NULL) AND '
            .'(weekday IS NULL OR weekday BETWEEN 1 AND 7) AND start_time < end_time AND timezone <> \'\' )'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS working_locations_one_default_office_ux');
        }

        Schema::dropIfExists('location_days');
        Schema::dropIfExists('working_locations');
    }
};
