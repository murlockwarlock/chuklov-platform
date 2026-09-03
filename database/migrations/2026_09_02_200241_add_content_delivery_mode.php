<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_sections', function (Blueprint $table): void {
            $table->string('delivery_mode', 16)->default('both')->after('body');
            $table->index(['organization_id', 'delivery_mode'], 'content_sections_org_delivery_mode_ix');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE content_sections ADD CONSTRAINT content_sections_delivery_mode_ck CHECK (delivery_mode IN ('telegram','mini_app','both'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE content_sections DROP CONSTRAINT IF EXISTS content_sections_delivery_mode_ck');
        }

        Schema::table('content_sections', function (Blueprint $table): void {
            $table->dropIndex('content_sections_org_delivery_mode_ix');
            $table->dropColumn('delivery_mode');
        });
    }
};
