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

        $columns = [
            ['client_email_auth_challenges', 'expires_at'],
            ['client_email_auth_challenges', 'consumed_at'],
            ['client_email_auth_challenges', 'created_at'],
            ['client_email_auth_challenges', 'updated_at'],
            ['client_channel_link_tokens', 'expires_at'],
            ['client_channel_link_tokens', 'consumed_at'],
            ['client_channel_link_tokens', 'created_at'],
            ['client_channel_link_tokens', 'updated_at'],
            ['legal_documents', 'effective_at'],
            ['legal_documents', 'published_at'],
            ['legal_documents', 'archived_at'],
            ['legal_documents', 'created_at'],
            ['legal_documents', 'updated_at'],
            ['audit_events', 'occurred_at'],
            ['audit_events', 'created_at'],
            ['client_consents', 'recorded_at'],
            ['client_consents', 'created_at'],
            ['client_consents', 'updated_at'],
            ['client_channel_identities', 'verified_at'],
            ['client_onboardings', 'completed_at'],
        ];

        foreach ($columns as [$table, $column]) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE TIMESTAMPTZ USING {$column} AT TIME ZONE 'UTC'");
        }
    }

    public function down(): void {}
};
