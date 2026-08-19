<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('external_image_url', 2048)->nullable()->after('image_path');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE services ADD CONSTRAINT services_image_source_pair '
                .'CHECK (image_path IS NULL OR external_image_url IS NULL)'
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS services_image_source_pair');
        }

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('external_image_url');
        });
    }
};
