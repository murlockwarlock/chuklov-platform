<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->string('title', 200);
            $table->string('category', 80)->nullable();
            $table->string('status', 24)->default('active');
            $table->foreignId('active_revision_id')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'status', 'type']);
        });

        Schema::create('knowledge_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('knowledge_source_id');
            $table->unsignedInteger('version');
            $table->string('status', 24)->default('pending');
            $table->text('content')->nullable();
            $table->string('storage_disk', 40)->nullable();
            $table->string('storage_path', 500)->nullable();
            $table->string('original_filename', 255)->nullable();
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('content_checksum', 64);
            $table->string('source_reference', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'knowledge_source_id', 'id']);
            $table->unique(['organization_id', 'knowledge_source_id', 'version']);
            $table->foreign(['organization_id', 'knowledge_source_id'])
                ->references(['organization_id', 'id'])->on('knowledge_sources')->restrictOnDelete();
            $table->index(['organization_id', 'knowledge_source_id', 'status']);
        });

        Schema::table('knowledge_sources', function (Blueprint $table): void {
            $table->foreign(['organization_id', 'id', 'active_revision_id'])
                ->references(['organization_id', 'knowledge_source_id', 'id'])
                ->on('knowledge_revisions')->restrictOnDelete();
        });

        Schema::create('knowledge_ingestion_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('knowledge_source_id');
            $table->foreignId('knowledge_revision_id');
            $table->string('configuration_key', 64);
            $table->string('status', 24)->default('pending');
            $table->string('chunk_strategy', 80);
            $table->string('chunk_version', 32);
            $table->unsignedInteger('chunk_target_characters');
            $table->unsignedInteger('chunk_maximum_characters');
            $table->unsignedInteger('chunk_overlap_characters');
            $table->string('embedding_provider', 80);
            $table->string('embedding_model', 160);
            $table->unsignedInteger('embedding_dimensions');
            $table->string('embedding_configuration_version', 80);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->timestampTz('processing_started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(
                ['organization_id', 'knowledge_source_id', 'knowledge_revision_id', 'id'],
                'knowledge_runs_org_source_revision_id_unique',
            );
            $table->unique(['organization_id', 'knowledge_revision_id', 'configuration_key']);
            $table->foreign(['organization_id', 'knowledge_source_id', 'knowledge_revision_id'])
                ->references(['organization_id', 'knowledge_source_id', 'id'])
                ->on('knowledge_revisions')->restrictOnDelete();
            $table->index(['organization_id', 'status', 'created_at']);
        });

        Schema::create('knowledge_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('knowledge_source_id');
            $table->foreignId('knowledge_revision_id');
            $table->foreignId('knowledge_ingestion_run_id');
            $table->unsignedInteger('chunk_index');
            $table->unsignedInteger('start_offset');
            $table->unsignedInteger('end_offset');
            $table->string('source_reference', 500)->nullable();
            $table->char('content_checksum', 64);
            $table->text('content');
            if (DB::getDriverName() === 'pgsql') {
                $table->vector('embedding', dimensions: (int) config('rag.embedding.dimensions'));
            } else {
                $table->json('embedding');
            }
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'knowledge_ingestion_run_id', 'chunk_index']);
            $table->foreign(['organization_id', 'knowledge_source_id', 'knowledge_revision_id'])
                ->references(['organization_id', 'knowledge_source_id', 'id'])
                ->on('knowledge_revisions')->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'knowledge_source_id', 'knowledge_revision_id', 'knowledge_ingestion_run_id'],
                'knowledge_chunks_run_provenance_foreign',
            )->references(['organization_id', 'knowledge_source_id', 'knowledge_revision_id', 'id'])
                ->on('knowledge_ingestion_runs')->restrictOnDelete();
            $table->index(['organization_id', 'knowledge_source_id', 'knowledge_revision_id']);
        });

        DB::statement("ALTER TABLE knowledge_sources ADD CONSTRAINT knowledge_sources_type_check CHECK (type IN ('authored_text', 'uploaded_text'))");
        DB::statement("ALTER TABLE knowledge_sources ADD CONSTRAINT knowledge_sources_status_check CHECK (status IN ('active', 'retired'))");
        DB::statement("ALTER TABLE knowledge_revisions ADD CONSTRAINT knowledge_revisions_status_check CHECK (status IN ('pending', 'processing', 'ready', 'failed', 'stale', 'retired'))");
        DB::statement("ALTER TABLE knowledge_ingestion_runs ADD CONSTRAINT knowledge_ingestion_runs_status_check CHECK (status IN ('pending', 'processing', 'ready', 'failed'))");
        DB::statement('ALTER TABLE knowledge_ingestion_runs ADD CONSTRAINT knowledge_ingestion_runs_dimensions_check CHECK (embedding_dimensions > 0)');

    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_ingestion_runs');
        Schema::table('knowledge_sources', function (Blueprint $table): void {
            $table->dropForeign(['organization_id', 'id', 'active_revision_id']);
        });
        Schema::dropIfExists('knowledge_revisions');
        Schema::dropIfExists('knowledge_sources');
    }
};
