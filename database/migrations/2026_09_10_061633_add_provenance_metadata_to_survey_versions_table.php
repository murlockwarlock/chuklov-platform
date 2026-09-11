<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('survey_versions', function (Blueprint $table) {
            $table->string('source', 40)->default('platform_default')->after('source_reference');
            $table->string('approval_status', 32)->default('draft')->after('source');
            $table->string('methodology', 120)->nullable()->after('approval_status');
            $table->index(['organization_id', 'source', 'approval_status']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE survey_versions ADD CONSTRAINT survey_versions_source_check CHECK (source IN ('platform_default', 'chuklov_approved'))");
            DB::statement("ALTER TABLE survey_versions ADD CONSTRAINT survey_versions_approval_status_check CHECK (approval_status IN ('draft', 'approved', 'rejected'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE survey_versions DROP CONSTRAINT IF EXISTS survey_versions_source_check');
            DB::statement('ALTER TABLE survey_versions DROP CONSTRAINT IF EXISTS survey_versions_approval_status_check');
        }

        Schema::table('survey_versions', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'source', 'approval_status']);
            $table->dropColumn(['source', 'approval_status', 'methodology']);
        });
    }
};
