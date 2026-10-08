<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->timestampTz('last_human_takeover_at')->nullable();
        });

        Schema::create('companion_turn_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('turn_id');
            $table->unsignedInteger('attempt_number');
            $table->string('execution_key', 64);
            $table->string('status', 24)->default('pending');
            $table->foreignId('ai_run_id')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->foreignId('output_message_id')->nullable();
            $table->foreignId('retry_requested_from_message_id')->nullable();
            $table->timestampTz('execution_deadline_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'companion_turn_attempts_org_id_unique');
            $table->unique(['organization_id', 'turn_id', 'attempt_number'], 'companion_turn_attempts_turn_number_unique');
            $table->unique(['organization_id', 'execution_key'], 'companion_turn_attempts_execution_key_unique');
            $table->unique(['organization_id', 'retry_requested_from_message_id'], 'companion_turn_attempts_retry_message_unique');
            $table->index(['organization_id', 'turn_id', 'status'], 'companion_turn_attempts_turn_status_index');
            $table->foreign(['organization_id', 'turn_id'], 'companion_turn_attempts_org_turn_fk')
                ->references(['organization_id', 'id'])
                ->on('companion_turns')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'ai_run_id'], 'companion_turn_attempts_org_ai_run_fk')
                ->references(['organization_id', 'id'])
                ->on('ai_runs')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'output_message_id'], 'companion_turn_attempts_org_output_message_fk')
                ->references(['organization_id', 'id'])
                ->on('conversation_messages')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'retry_requested_from_message_id'], 'companion_turn_attempts_org_retry_message_fk')
                ->references(['organization_id', 'id'])
                ->on('conversation_messages')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE companion_turn_attempts ADD CONSTRAINT companion_turn_attempts_status_check CHECK (status IN ('pending', 'processing', 'succeeded', 'failed', 'cancelled'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('companion_turn_attempts');

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn('last_human_takeover_at');
        });
    }
};
