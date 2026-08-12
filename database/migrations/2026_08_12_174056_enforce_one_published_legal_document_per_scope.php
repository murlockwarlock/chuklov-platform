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
            WITH ranked_documents AS (
                SELECT
                    id,
                    ROW_NUMBER() OVER (
                        PARTITION BY organization_id, document_type, locale
                        ORDER BY published_at DESC NULLS LAST, id DESC
                    ) AS document_rank
                FROM legal_documents
                WHERE status = 'published'
            )
            UPDATE legal_documents AS documents
            SET
                status = 'archived',
                archived_at = COALESCE(archived_at, CURRENT_TIMESTAMP)
            FROM ranked_documents
            WHERE documents.id = ranked_documents.id
              AND ranked_documents.document_rank > 1
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX legal_documents_current_published_scope_unique
            ON legal_documents (organization_id, document_type, locale)
            WHERE status = 'published'
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS legal_documents_current_published_scope_unique');
    }
};
