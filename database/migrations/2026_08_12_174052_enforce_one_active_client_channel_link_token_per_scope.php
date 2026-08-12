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

        DB::statement(<<<'SQL'
            WITH ranked_tokens AS (
                SELECT
                    id,
                    ROW_NUMBER() OVER (
                        PARTITION BY organization_id, client_id, channel, flow
                        ORDER BY created_at DESC, id DESC
                    ) AS token_rank
                FROM client_channel_link_tokens
                WHERE consumed_at IS NULL
            )
            UPDATE client_channel_link_tokens AS tokens
            SET consumed_at = CURRENT_TIMESTAMP
            FROM ranked_tokens
            WHERE tokens.id = ranked_tokens.id
              AND ranked_tokens.token_rank > 1
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX client_channel_link_tokens_active_scope_unique
            ON client_channel_link_tokens (organization_id, client_id, channel, flow)
            WHERE consumed_at IS NULL
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS client_channel_link_tokens_active_scope_unique');
    }
};
