<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('capability', 80);
            $table->string('workflow_key', 80);
            $table->string('origin', 32)->default('user');
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable();
            $table->string('status', 32)->default('queued');
            $table->string('execution_mode', 32)->default('sync');
            $table->foreignId('prompt_id')->nullable();
            $table->foreignId('prompt_version_id')->nullable();
            $table->foreignId('model_config_id')->nullable();
            $table->foreignId('model_release_id')->nullable();
            $table->string('requested_provider', 64)->nullable();
            $table->string('requested_model', 120)->nullable();
            $table->string('actual_provider', 64)->nullable();
            $table->string('actual_model', 120)->nullable();
            $table->json('input_references');
            $table->char('rendered_prompt_digest', 64)->nullable();
            $table->json('context_provenance');
            $table->string('structured_output_schema_version', 64)->nullable();
            $table->boolean('structured_output_valid')->default(true);
            $table->json('token_usage');
            $table->bigInteger('provider_cost_minor_units')->nullable();
            $table->bigInteger('settled_estimated_cost_minor_units')->nullable();
            $table->string('cost_currency', 10)->default('USD');
            $table->unsignedInteger('latency_ms')->default(0);
            $table->unsignedInteger('attempt_count')->default(1);
            $table->string('error_category', 64)->nullable();
            $table->text('error_message_sanitized')->nullable();
            $table->string('human_review_status', 32)->default('not_required');
            $table->string('idempotency_key', 120)->nullable();
            $table->string('worker_lease_token', 64)->nullable();
            $table->timestampTz('worker_lease_expires_at')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'idempotency_key']);
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])->on('clients')->nullOnDelete();
            $table->foreign(['organization_id', 'prompt_id'])
                ->references(['organization_id', 'id'])->on('ai_prompts')->nullOnDelete();
            $table->foreign(['organization_id', 'prompt_version_id'])
                ->references(['organization_id', 'id'])->on('ai_prompt_versions')->nullOnDelete();
            $table->foreign(['organization_id', 'model_config_id'])
                ->references(['organization_id', 'id'])->on('ai_model_configurations')->nullOnDelete();
            $table->foreign(['organization_id', 'model_release_id'])
                ->references(['organization_id', 'id'])->on('ai_model_releases')->nullOnDelete();
            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['organization_id', 'capability', 'created_at']);
            $table->index(['organization_id', 'human_review_status', 'created_at']);
        });

        Schema::create('ai_run_payloads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('ai_run_id');
            $table->unsignedInteger('encryption_key_version')->default(1);
            $table->text('encrypted_system_prompt')->nullable();
            $table->text('encrypted_user_prompt')->nullable();
            $table->text('encrypted_output_text')->nullable();
            $table->text('encrypted_output_payload')->nullable();
            $table->text('encrypted_human_review_notes')->nullable();
            $table->text('encrypted_human_edited_output')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'ai_run_id']);
            $table->foreign(['organization_id', 'ai_run_id'])
                ->references(['organization_id', 'id'])->on('ai_runs')->cascadeOnDelete();
        });

        Schema::create('ai_run_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('ai_run_id');
            $table->unsignedInteger('attempt_number');
            $table->string('provider', 64);
            $table->string('model', 120);
            $table->foreignId('model_release_id')->nullable();
            $table->foreignId('credential_id')->nullable();
            $table->uuid('credential_revision')->nullable();
            $table->string('status', 32);
            $table->string('retry_or_failover_reason', 200)->nullable();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->json('token_usage');
            $table->unsignedBigInteger('reserved_cost_minor_units')->default(0);
            $table->date('budget_usage_date');
            $table->string('budget_reservation_status', 32)->default('reserved');
            $table->bigInteger('settled_estimated_cost_minor_units')->nullable();
            $table->bigInteger('provider_cost_minor_units')->nullable();
            $table->json('pricing_snapshot');
            $table->string('error_category', 64)->nullable();
            $table->text('error_message_sanitized')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'ai_run_id', 'attempt_number']);
            $table->foreign(['organization_id', 'ai_run_id'])
                ->references(['organization_id', 'id'])->on('ai_runs')->cascadeOnDelete();
            $table->foreign(['organization_id', 'model_release_id'])
                ->references(['organization_id', 'id'])->on('ai_model_releases')->nullOnDelete();
            $table->foreign(['organization_id', 'credential_id'])
                ->references(['organization_id', 'id'])->on('organization_credentials')->nullOnDelete();
            $table->index(['organization_id', 'budget_usage_date', 'budget_reservation_status']);
        });

        Schema::create('ai_run_tool_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('ai_run_id');
            $table->unsignedInteger('call_index');
            $table->string('tool_name', 80);
            $table->boolean('is_read_only')->default(true);
            $table->char('input_digest', 64);
            $table->string('execution_status', 32)->default('succeeded');
            $table->unsignedInteger('latency_ms')->default(0);
            $table->text('error_sanitized')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'ai_run_id', 'call_index']);
            $table->foreign(['organization_id', 'ai_run_id'])
                ->references(['organization_id', 'id'])->on('ai_runs')->cascadeOnDelete();
        });

        Schema::create('ai_run_rag_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('ai_run_id');
            $table->unsignedInteger('reference_index');
            $table->foreignId('knowledge_source_id');
            $table->foreignId('knowledge_revision_id');
            $table->foreignId('knowledge_chunk_id');
            $table->unsignedInteger('chunk_index');
            $table->float('similarity_score');
            $table->string('configuration_key', 64);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'ai_run_id', 'reference_index']);
            $table->foreign(['organization_id', 'ai_run_id'])
                ->references(['organization_id', 'id'])->on('ai_runs')->cascadeOnDelete();
            $table->foreign(['organization_id', 'knowledge_source_id'])
                ->references(['organization_id', 'id'])->on('knowledge_sources')->cascadeOnDelete();
            $table->foreign(['organization_id', 'knowledge_revision_id'])
                ->references(['organization_id', 'id'])->on('knowledge_revisions')->cascadeOnDelete();
            $table->foreign(['organization_id', 'knowledge_chunk_id'])
                ->references(['organization_id', 'id'])->on('knowledge_chunks')->cascadeOnDelete();
        });

        Schema::create('ai_run_human_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('ai_run_id');
            $table->unsignedInteger('review_step');
            $table->string('decision', 32);
            $table->foreignId('reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('safe_reason_code', 64)->nullable();
            $table->timestampTz('reviewed_at');
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'ai_run_id', 'review_step']);
            $table->foreign(['organization_id', 'ai_run_id'])
                ->references(['organization_id', 'id'])->on('ai_runs')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_status_check CHECK (status IN ('queued', 'running', 'succeeded', 'failed', 'cancelled', 'timed_out', 'invalid_output'))");
            DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_origin_check CHECK (origin IN ('user', 'system_scenario', 'playground', 'evaluation', 'client_portal'))");
            DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_mode_check CHECK (execution_mode IN ('sync', 'async', 'playground', 'evaluation'))");
            DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_human_review_status_check CHECK (human_review_status IN ('not_required', 'pending_review', 'accepted', 'rejected', 'edited_and_accepted'))");
            DB::statement("ALTER TABLE ai_run_attempts ADD CONSTRAINT ai_run_attempts_status_check CHECK (status IN ('running', 'succeeded', 'failed', 'timed_out'))");
            DB::statement("ALTER TABLE ai_run_attempts ADD CONSTRAINT ai_run_attempts_reservation_status_check CHECK (budget_reservation_status IN ('reserved', 'settled', 'released', 'conservatively_charged'))");
            DB::statement("ALTER TABLE ai_run_human_reviews ADD CONSTRAINT ai_run_human_reviews_decision_check CHECK (decision IN ('accepted', 'rejected', 'edited_and_accepted'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_run_human_reviews');
        Schema::dropIfExists('ai_run_rag_references');
        Schema::dropIfExists('ai_run_tool_calls');
        Schema::dropIfExists('ai_run_attempts');
        Schema::dropIfExists('ai_run_payloads');
        Schema::dropIfExists('ai_runs');
    }
};
