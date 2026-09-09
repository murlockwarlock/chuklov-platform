<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_revisions', function (Blueprint $table): void {
            $table->char('original_checksum', 64)->nullable()->after('content_checksum');
            $table->string('parser_type', 80)->nullable()->after('original_checksum');
            $table->string('parser_version', 120)->nullable()->after('parser_type');
            $table->string('extraction_status', 32)->default('ready')->after('parser_version');
            $table->json('extraction_diagnostics')->nullable()->after('extraction_status');
            $table->string('ai_parser_capability', 120)->nullable()->after('extraction_diagnostics');
            $table->string('ai_prompt_version', 80)->nullable()->after('ai_parser_capability');
            $table->string('ai_run_reference', 191)->nullable()->after('ai_prompt_version');
            $table->timestampTz('extracted_at')->nullable()->after('ai_run_reference');
            $table->index(['organization_id', 'knowledge_source_id', 'extraction_status'], 'knowledge_revisions_extraction_status_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE knowledge_revisions ADD CONSTRAINT knowledge_revisions_extraction_status_check CHECK (extraction_status IN ('ready', 'text_not_found', 'suspicious', 'failed', 'ai_parse_requested'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE knowledge_revisions DROP CONSTRAINT IF EXISTS knowledge_revisions_extraction_status_check');
        }

        Schema::table('knowledge_revisions', function (Blueprint $table): void {
            $table->dropIndex('knowledge_revisions_extraction_status_idx');
            $table->dropColumn([
                'original_checksum',
                'parser_type',
                'parser_version',
                'extraction_status',
                'extraction_diagnostics',
                'ai_parser_capability',
                'ai_prompt_version',
                'ai_run_reference',
                'extracted_at',
            ]);
        });
    }
};
