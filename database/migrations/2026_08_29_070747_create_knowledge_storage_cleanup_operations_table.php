<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_storage_cleanup_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('cleanup_key', 64);
            $table->string('storage_disk', 40);
            $table->string('storage_path', 500);
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('available_at')->useCurrent();
            $table->timestampTz('processing_started_at')->nullable();
            $table->char('processing_token', 64)->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'cleanup_key'], 'knowledge_cleanup_org_key_unique');
            $table->index(['organization_id', 'status', 'available_at'], 'knowledge_cleanup_due_index');
            $table->index(['organization_id', 'status', 'processing_started_at'], 'knowledge_cleanup_stale_index');
            $table->index(['status', 'available_at', 'id'], 'knowledge_cleanup_global_due_idx');
            $table->index(['status', 'processing_started_at', 'id'], 'knowledge_cleanup_global_stale_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE knowledge_storage_cleanup_operations ADD CONSTRAINT knowledge_cleanup_status_check CHECK (status IN ('pending', 'processing', 'retryable', 'succeeded', 'protected', 'failed'))");
        }

        Schema::table('knowledge_revisions', function (Blueprint $table): void {
            $table->index(['storage_disk', 'storage_path'], 'knowledge_revisions_storage_identity_idx');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_revisions', function (Blueprint $table): void {
            $table->dropIndex('knowledge_revisions_storage_identity_idx');
        });
        Schema::dropIfExists('knowledge_storage_cleanup_operations');
    }
};
