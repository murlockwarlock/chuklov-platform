<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_ingestion_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('knowledge_source_id');
            $table->foreignId('knowledge_revision_id');
            $table->foreignId('knowledge_ingestion_run_id');
            $table->unsignedInteger('attempt_number');
            $table->string('status', 24)->default('processing');
            $table->string('error_code', 80)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(
                ['organization_id', 'knowledge_ingestion_run_id', 'attempt_number'],
                'knowledge_ingestion_attempts_org_run_attempt_unique',
            );
            $table->foreign(['organization_id', 'knowledge_source_id'])
                ->references(['organization_id', 'id'])
                ->on('knowledge_sources')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'knowledge_source_id', 'knowledge_revision_id'])
                ->references(['organization_id', 'knowledge_source_id', 'id'])
                ->on('knowledge_revisions')
                ->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'knowledge_source_id', 'knowledge_revision_id', 'knowledge_ingestion_run_id'],
                'knowledge_ingestion_attempts_run_provenance_foreign',
            )->references(['organization_id', 'knowledge_source_id', 'knowledge_revision_id', 'id'])
                ->on('knowledge_ingestion_runs')
                ->restrictOnDelete();
            $table->index(['organization_id', 'knowledge_source_id', 'knowledge_revision_id', 'status']);
            $table->index(['organization_id', 'status', 'started_at']);
        });

        DB::statement("ALTER TABLE knowledge_ingestion_attempts ADD CONSTRAINT knowledge_ingestion_attempts_status_check CHECK (status IN ('processing', 'ready', 'failed', 'abandoned'))");
        DB::statement('ALTER TABLE knowledge_ingestion_attempts ADD CONSTRAINT knowledge_ingestion_attempts_number_check CHECK (attempt_number > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_ingestion_attempts');
    }
};
