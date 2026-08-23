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
            $table->string('conversation_type', 32)->default('channel')->after('channel');
            $table->string('automation_state', 32)->default('ai_active')->after('conversation_type');
            $table->unsignedInteger('context_epoch')->default(1)->after('automation_state');
            $table->index(['organization_id', 'client_id', 'conversation_type', 'last_message_at'], 'conversations_companion_history_index');
        });

        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->foreignId('author_user_id')->nullable()->after('author_type');
            $table->text('encrypted_body')->nullable()->after('body');
            $table->unsignedInteger('encryption_key_version')->nullable()->after('encrypted_body');
            $table->unsignedInteger('companion_context_epoch')->nullable()->after('encryption_key_version');
            $table->index(['organization_id', 'conversation_id', 'occurred_at', 'id'], 'conversation_messages_companion_history_index');
            $table->unique(['organization_id', 'id'], 'conversation_messages_organization_id_id_unique');
            $table->foreign('author_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('medical_attachments', function (Blueprint $table): void {
            $table->unique(['organization_id', 'id'], 'medical_attachments_organization_id_id_unique');
        });

        Schema::create('conversation_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('conversation_id');
            $table->foreignId('client_id');
            $table->string('channel', 32);
            $table->string('external_key', 191);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'channel', 'external_key'], 'conversation_bindings_org_channel_external_unique');
            $table->unique(['organization_id', 'conversation_id', 'channel'], 'conversation_bindings_org_conversation_channel_unique');
            $table->index(['organization_id', 'client_id', 'channel']);
            $table->foreign(['organization_id', 'conversation_id'], 'conversation_bindings_org_conversation_fk')
                ->references(['organization_id', 'id'])
                ->on('conversations')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'client_id'], 'conversation_bindings_org_client_fk')
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->cascadeOnDelete();
        });

        Schema::create('companion_turns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('conversation_id');
            $table->unsignedInteger('sequence');
            $table->unsignedInteger('context_epoch')->default(1);
            $table->foreignId('inbound_message_id');
            $table->timestampTz('burst_expires_at')->nullable();
            $table->unsignedSmallInteger('burst_message_count')->default(1);
            $table->unsignedInteger('burst_text_characters')->default(0);
            $table->string('input_modality', 32)->default('text');
            $table->string('media_group_id', 191)->nullable();
            $table->unsignedSmallInteger('input_item_count')->default(0);
            $table->unsignedBigInteger('input_total_bytes')->default(0);
            $table->string('input_failure_code', 64)->nullable();
            $table->timestampTz('sealed_at')->nullable();
            $table->foreignId('outbound_message_id')->nullable();
            $table->foreignId('ai_run_id')->nullable();
            $table->string('origin_channel', 32);
            $table->string('origin_external_id', 191)->nullable();
            $table->string('transport_chat_id', 64)->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->char('request_hash', 64);
            $table->string('status', 32)->default('pending');
            $table->string('failure_code', 64)->nullable();
            $table->string('processing_lease_token', 64)->nullable();
            $table->timestampTz('processing_lease_expires_at')->nullable();
            $table->string('typing_owner_token', 64)->nullable();
            $table->unsignedInteger('typing_heartbeat_sequence')->default(0);
            $table->boolean('typing_active')->default(false);
            $table->string('typing_chat_id', 64)->nullable();
            $table->timestampTz('accepted_at');
            $table->timestampTz('processing_started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('escalated_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'companion_turns_org_id_unique');
            $table->unique(['organization_id', 'conversation_id', 'sequence'], 'companion_turns_org_conversation_sequence_unique');
            $table->unique(['organization_id', 'idempotency_key'], 'companion_turns_org_idempotency_unique');
            $table->unique(['organization_id', 'origin_channel', 'origin_external_id'], 'companion_turns_org_origin_unique');
            $table->index(['organization_id', 'conversation_id', 'status', 'sequence'], 'companion_turns_order_index');
            $table->index(['organization_id', 'client_id', 'status', 'accepted_at'], 'companion_turns_pending_index');
            $table->index(['organization_id', 'conversation_id', 'context_epoch', 'media_group_id', 'status'], 'companion_turns_media_group_index');
            $table->index(['organization_id', 'status', 'processing_lease_expires_at'], 'companion_turns_lease_index');
            $table->foreign(['organization_id', 'client_id'], 'companion_turns_org_client_fk')
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'conversation_id'], 'companion_turns_org_conversation_fk')
                ->references(['organization_id', 'id'])
                ->on('conversations')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'inbound_message_id'], 'companion_turns_org_inbound_message_fk')
                ->references(['organization_id', 'id'])
                ->on('conversation_messages')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'outbound_message_id'], 'companion_turns_org_outbound_message_fk')
                ->references(['organization_id', 'id'])
                ->on('conversation_messages')
                ->nullOnDelete();
            $table->foreign(['organization_id', 'ai_run_id'], 'companion_turns_org_ai_run_fk')
                ->references(['organization_id', 'id'])
                ->on('ai_runs')
                ->nullOnDelete();
        });

        Schema::create('companion_turn_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('turn_id');
            $table->foreignId('conversation_message_id');
            $table->unsignedInteger('sequence');
            $table->char('request_hash', 64);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'companion_turn_messages_org_id_unique');
            $table->unique(['organization_id', 'turn_id', 'conversation_message_id'], 'companion_turn_messages_turn_message_unique');
            $table->unique(['organization_id', 'conversation_message_id'], 'companion_turn_messages_org_message_unique');
            $table->index(['organization_id', 'turn_id', 'sequence'], 'companion_turn_messages_order_index');
            $table->foreign(['organization_id', 'turn_id'], 'companion_turn_messages_org_turn_fk')
                ->references(['organization_id', 'id'])
                ->on('companion_turns')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'conversation_message_id'], 'companion_turn_messages_org_message_fk')
                ->references(['organization_id', 'id'])
                ->on('conversation_messages')
                ->cascadeOnDelete();
        });

        Schema::create('companion_message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('conversation_id');
            $table->foreignId('turn_id');
            $table->foreignId('conversation_message_id');
            $table->foreignId('medical_attachment_id');
            $table->string('media_group_id', 191)->nullable();
            $table->unsignedBigInteger('source_ordinal')->nullable();
            $table->unsignedInteger('item_index')->default(1);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'companion_message_attachments_org_id_unique');
            $table->unique(['organization_id', 'conversation_message_id', 'medical_attachment_id'], 'companion_message_attachments_message_file_unique');
            $table->index(['organization_id', 'turn_id', 'source_ordinal'], 'companion_message_attachments_turn_order_index');
            $table->foreign(['organization_id', 'client_id'], 'companion_message_attachments_org_client_fk')
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'conversation_id'], 'companion_message_attachments_org_conversation_fk')
                ->references(['organization_id', 'id'])
                ->on('conversations')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'turn_id'], 'companion_message_attachments_org_turn_fk')
                ->references(['organization_id', 'id'])
                ->on('companion_turns')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'conversation_message_id'], 'companion_message_attachments_org_message_fk')
                ->references(['organization_id', 'id'])
                ->on('conversation_messages')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'medical_attachment_id'], 'companion_message_attachments_org_attachment_fk')
                ->references(['organization_id', 'id'])
                ->on('medical_attachments')
                ->restrictOnDelete();
        });

        Schema::create('companion_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('turn_id')->nullable();
            $table->foreignId('conversation_message_id');
            $table->string('channel', 32);
            $table->string('recipient_external_id', 191);
            $table->unsignedInteger('chunk_index');
            $table->unsignedInteger('chunk_count');
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('provider_reference', 191)->nullable();
            $table->string('last_error_code', 120)->nullable();
            $table->string('processing_lease_token', 64)->nullable();
            $table->timestampTz('processing_lease_expires_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'companion_deliveries_org_id_unique');
            $table->unique(['organization_id', 'turn_id', 'chunk_index'], 'companion_deliveries_turn_chunk_unique');
            $table->unique(['organization_id', 'conversation_message_id', 'chunk_index'], 'companion_deliveries_message_chunk_unique');
            $table->index(['organization_id', 'status', 'next_attempt_at'], 'companion_deliveries_pending_index');
            $table->foreign(['organization_id', 'turn_id'], 'companion_deliveries_org_turn_fk')
                ->references(['organization_id', 'id'])
                ->on('companion_turns')
                ->nullOnDelete();
            $table->foreign(['organization_id', 'conversation_message_id'], 'companion_deliveries_org_message_fk')
                ->references(['organization_id', 'id'])
                ->on('conversation_messages')
                ->cascadeOnDelete();
        });

        Schema::create('companion_escalations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('conversation_id');
            $table->foreignId('turn_id');
            $table->foreignId('ai_run_id')->nullable();
            $table->string('reason', 64);
            $table->string('status', 32)->default('open');
            $table->json('safe_metadata');
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('opened_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'companion_escalations_org_id_unique');
            $table->index(['organization_id', 'conversation_id', 'status', 'opened_at'], 'companion_escalations_open_index');
            $table->foreign(['organization_id', 'client_id'], 'companion_escalations_org_client_fk')
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'conversation_id'], 'companion_escalations_org_conversation_fk')
                ->references(['organization_id', 'id'])
                ->on('conversations')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'turn_id'], 'companion_escalations_org_turn_fk')
                ->references(['organization_id', 'id'])
                ->on('companion_turns')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'ai_run_id'], 'companion_escalations_org_ai_run_fk')
                ->references(['organization_id', 'id'])
                ->on('ai_runs')
                ->nullOnDelete();
        });

        Schema::create('companion_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('conversation_id');
            $table->foreignId('message_id');
            $table->foreignId('turn_id')->nullable();
            $table->foreignId('ai_run_id')->nullable();
            $table->string('value', 32);
            $table->string('reason', 64)->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'companion_feedback_org_id_unique');
            $table->unique(['organization_id', 'client_id', 'message_id'], 'companion_feedback_client_message_unique');
            $table->index(['organization_id', 'conversation_id', 'created_at'], 'companion_feedback_history_index');
            $table->foreign(['organization_id', 'client_id'], 'companion_feedback_org_client_fk')
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'conversation_id'], 'companion_feedback_org_conversation_fk')
                ->references(['organization_id', 'id'])
                ->on('conversations')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'message_id'], 'companion_feedback_org_message_fk')
                ->references(['organization_id', 'id'])
                ->on('conversation_messages')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'turn_id'], 'companion_feedback_org_turn_fk')
                ->references(['organization_id', 'id'])
                ->on('companion_turns')
                ->nullOnDelete();
            $table->foreign(['organization_id', 'ai_run_id'], 'companion_feedback_org_ai_run_fk')
                ->references(['organization_id', 'id'])
                ->on('ai_runs')
                ->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_companion_type_check CHECK (conversation_type IN ('channel', 'client_companion'))");
            DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_automation_state_check CHECK (automation_state IN ('ai_active', 'human_handoff'))");
            DB::statement("ALTER TABLE companion_turns ADD CONSTRAINT companion_turns_status_check CHECK (status IN ('pending', 'processing', 'completed', 'failed', 'escalated', 'paused', 'cancelled'))");
            DB::statement("ALTER TABLE companion_deliveries ADD CONSTRAINT companion_deliveries_status_check CHECK (status IN ('pending', 'processing', 'delivered', 'failed'))");
            DB::statement("ALTER TABLE companion_escalations ADD CONSTRAINT companion_escalations_status_check CHECK (status IN ('open', 'resolved'))");
            DB::statement("ALTER TABLE companion_feedback ADD CONSTRAINT companion_feedback_value_check CHECK (value IN ('helpful', 'not_helpful'))");
            DB::statement("CREATE UNIQUE INDEX companion_escalations_one_open_per_conversation ON companion_escalations (organization_id, conversation_id) WHERE status = 'open'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS companion_escalations_one_open_per_conversation');
        }

        Schema::dropIfExists('companion_feedback');
        Schema::dropIfExists('companion_message_attachments');
        Schema::dropIfExists('companion_turn_messages');
        Schema::dropIfExists('companion_escalations');
        Schema::dropIfExists('companion_deliveries');
        Schema::dropIfExists('companion_turns');
        Schema::dropIfExists('conversation_bindings');

        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->dropForeign(['author_user_id']);
            $table->dropUnique('conversation_messages_organization_id_id_unique');
            $table->dropColumn(['author_user_id', 'encrypted_body', 'encryption_key_version', 'companion_context_epoch']);
        });

        Schema::table('medical_attachments', function (Blueprint $table): void {
            $table->dropUnique('medical_attachments_organization_id_id_unique');
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_companion_history_index');
            $table->dropColumn(['conversation_type', 'automation_state', 'context_epoch']);
        });
    }
};
