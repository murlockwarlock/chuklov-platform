<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('specialists', function (Blueprint $table): void {
            $table->string('viewer_timezone', 64)->nullable()->after('timezone');
            $table->string('viewer_timezone_source', 24)->default('organization')->after('viewer_timezone');
            $table->string('viewer_timezone_suggestion', 64)->nullable()->after('viewer_timezone_source');
            $table->index(['organization_id', 'viewer_timezone_source'], 'specialists_org_viewer_timezone_source_ix');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignId('working_location_id')->nullable()->after('location');
            $table->string('location_area', 160)->nullable()->after('working_location_id');
            $table->json('location_snapshot')->nullable()->after('location_area');
            $table->foreign(['organization_id', 'working_location_id'])
                ->references(['organization_id', 'id'])
                ->on('working_locations')
                ->restrictOnDelete();
            $table->index(['organization_id', 'working_location_id', 'starts_at'], 'bookings_org_working_location_starts_ix');
            $table->index(['organization_id', 'location_area', 'starts_at'], 'bookings_org_location_area_starts_ix');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE specialists ADD CONSTRAINT specialists_viewer_timezone_source_ck '
            ."CHECK (viewer_timezone_source IN ('organization','device','manual'))"
        );
        DB::statement(
            'ALTER TABLE bookings ADD CONSTRAINT bookings_location_shape_ck '
            ."CHECK ((working_location_id IS NULL OR visit_format = 'office') AND "
            ."(location_area IS NULL OR visit_format = 'home'))"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_location_shape_ck');
            DB::statement('ALTER TABLE specialists DROP CONSTRAINT IF EXISTS specialists_viewer_timezone_source_ck');
        }

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropForeign(['organization_id', 'working_location_id']);
            $table->dropIndex('bookings_org_working_location_starts_ix');
            $table->dropIndex('bookings_org_location_area_starts_ix');
            $table->dropColumn(['working_location_id', 'location_area', 'location_snapshot']);
        });
        Schema::table('specialists', function (Blueprint $table): void {
            $table->dropIndex('specialists_org_viewer_timezone_source_ix');
            $table->dropColumn(['viewer_timezone', 'viewer_timezone_source', 'viewer_timezone_suggestion']);
        });
    }
};
