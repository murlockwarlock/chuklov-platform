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
            ['client_channel_identities', 'created_at'],
            ['client_channel_identities', 'updated_at'],
            ['client_onboardings', 'created_at'],
            ['client_onboardings', 'updated_at'],
            ['conversations', 'started_at'],
            ['conversations', 'last_message_at'],
            ['conversations', 'created_at'],
            ['conversations', 'updated_at'],
            ['conversation_messages', 'occurred_at'],
            ['conversation_messages', 'created_at'],
            ['conversation_messages', 'updated_at'],
        ];

        foreach ($columns as [$table, $column]) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE TIMESTAMPTZ USING {$column} AT TIME ZONE 'UTC'");
        }
    }

    public function down(): void {}
};
