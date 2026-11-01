<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX analytics_client_groups_org_lower_name_unique ON analytics_client_groups (organization_id, lower(name))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS analytics_client_groups_org_lower_name_unique');
    }
};
