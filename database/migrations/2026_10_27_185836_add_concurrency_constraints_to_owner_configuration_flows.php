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

        DB::statement("CREATE UNIQUE INDEX b2b_form_config_one_draft_per_org ON b2b_form_configurations (organization_id) WHERE status = 'draft'");
        DB::statement("CREATE UNIQUE INDEX b2b_form_config_one_published_per_org ON b2b_form_configurations (organization_id) WHERE status = 'published'");
        DB::statement('CREATE UNIQUE INDEX patient_access_grants_one_active_per_subject ON patient_access_grants (organization_id, patient_profile_id, grantee_client_id, scope) WHERE revoked_at IS NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS b2b_form_config_one_draft_per_org');
        DB::statement('DROP INDEX IF EXISTS b2b_form_config_one_published_per_org');
        DB::statement('DROP INDEX IF EXISTS patient_access_grants_one_active_per_subject');
    }
};
