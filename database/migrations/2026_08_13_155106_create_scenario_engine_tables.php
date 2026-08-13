<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('template_key', 120);
            $table->string('name', 160);
            $table->string('locale', 16);
            $table->string('purpose', 32);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'template_key', 'locale']);
            $table->index(['organization_id', 'purpose', 'is_active']);
        });

        Schema::create('notification_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('template_id');
            $table->unsignedInteger('version');
            $table->string('status', 32);
            $table->string('subject', 255)->nullable();
            $table->text('body');
            $table->json('variables');
            $table->foreignId('created_by_user_id')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'template_id', 'version']);
            $table->foreign(['organization_id', 'template_id'])
                ->references(['organization_id', 'id'])
                ->on('notification_templates')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'created_by_user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->nullOnDelete();
            $table->index(['organization_id', 'template_id', 'status']);
        });

        Schema::create('organization_channel_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id');
            $table->string('channel', 32);
            $table->string('external_id', 191);
            $table->string('verification_status', 32);
            $table->string('verification_method', 64)->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'user_id', 'channel']);
            $table->unique(['organization_id', 'channel', 'external_id']);
            $table->foreign(['organization_id', 'user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->restrictOnDelete();
            $table->index(['organization_id', 'user_id', 'verification_status']);
        });

        Schema::create('scenario_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('rule_key', 120);
            $table->string('name', 160);
            $table->string('trigger_event', 120);
            $table->boolean('is_enabled')->default(false);
            $table->unsignedInteger('delay_value')->default(0);
            $table->string('delay_unit', 16);
            $table->string('purpose', 32);
            $table->json('conditions');
            $table->json('recipient_strategy');
            $table->json('channel_priority');
            $table->foreignId('template_version_id');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by_user_id')->nullable();
            $table->foreignId('updated_by_user_id')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'rule_key']);
            $table->foreign(['organization_id', 'template_version_id'])
                ->references(['organization_id', 'id'])
                ->on('notification_template_versions')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'created_by_user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->nullOnDelete();
            $table->foreign(['organization_id', 'updated_by_user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->nullOnDelete();
            $table->index(['organization_id', 'trigger_event', 'is_enabled']);
        });

        Schema::create('scenario_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('event_name', 120);
            $table->string('aggregate_type', 160)->nullable();
            $table->string('aggregate_id', 128)->nullable();
            $table->timestampTz('occurred_at');
            $table->json('payload');
            $table->string('correlation_id', 128)->nullable();
            $table->string('causation_id', 128)->nullable();
            $table->string('idempotency_key', 191);
            $table->string('status', 32);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('available_at')->useCurrent();
            $table->timestampTz('processing_started_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->string('last_error_code', 120)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['organization_id', 'status', 'available_at']);
            $table->index(['organization_id', 'aggregate_type', 'aggregate_id']);
        });

        Schema::create('scenario_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('scenario_event_id');
            $table->foreignId('scenario_rule_id');
            $table->string('recipient_type', 32);
            $table->foreignId('client_id')->nullable();
            $table->foreignId('recipient_user_id')->nullable();
            $table->foreignId('template_version_id');
            $table->string('trigger_event', 120);
            $table->unsignedInteger('rule_version');
            $table->string('purpose', 32);
            $table->json('channel_priority');
            $table->json('render_context');
            $table->string('materialization_key', 191);
            $table->timestampTz('scheduled_for');
            $table->string('status', 32);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('processing_started_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('suppressed_at')->nullable();
            $table->string('terminal_reason', 160)->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'materialization_key']);
            $table->foreign(['organization_id', 'scenario_event_id'])
                ->references(['organization_id', 'id'])
                ->on('scenario_events')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'scenario_rule_id'])
                ->references(['organization_id', 'id'])
                ->on('scenario_rules')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'recipient_user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'template_version_id'])
                ->references(['organization_id', 'id'])
                ->on('notification_template_versions')
                ->restrictOnDelete();
            $table->index(['organization_id', 'status', 'scheduled_for']);
            $table->index(['organization_id', 'client_id', 'scheduled_for']);
        });

        Schema::create('scenario_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('scenario_action_id');
            $table->string('channel', 32);
            $table->unsignedSmallInteger('priority');
            $table->string('status', 32);
            $table->string('idempotency_key', 191);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('processing_started_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->string('last_error_code', 120)->nullable();
            $table->string('terminal_reason', 160)->nullable();
            $table->string('provider_reference', 191)->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'idempotency_key']);
            $table->unique(['organization_id', 'scenario_action_id', 'channel']);
            $table->foreign(['organization_id', 'scenario_action_id'])
                ->references(['organization_id', 'id'])
                ->on('scenario_actions')
                ->cascadeOnDelete();
            $table->index(['organization_id', 'status', 'next_attempt_at']);
        });

        Schema::create('scenario_delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('scenario_delivery_id');
            $table->unsignedInteger('attempt_number');
            $table->string('outcome', 32);
            $table->string('error_code', 120)->nullable();
            $table->string('provider_reference', 191)->nullable();
            $table->timestampTz('attempted_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'id']);
            $table->unique(
                ['organization_id', 'scenario_delivery_id', 'attempt_number'],
                'scenario_delivery_attempts_org_delivery_attempt_unique',
            );
            $table->foreign(['organization_id', 'scenario_delivery_id'])
                ->references(['organization_id', 'id'])
                ->on('scenario_deliveries')
                ->cascadeOnDelete();
            $table->index(['organization_id', 'scenario_delivery_id', 'attempted_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_purpose_check '
            ."CHECK (purpose IN ('service', 'transactional'))",
        );
        DB::statement(
            'ALTER TABLE notification_template_versions ADD CONSTRAINT notification_template_versions_status_check '
            ."CHECK (status IN ('draft', 'published', 'archived') AND version > 0)",
        );
        DB::statement(
            'ALTER TABLE organization_channel_identities ADD CONSTRAINT organization_channel_identities_status_check '
            ."CHECK (verification_status IN ('unverified', 'verified', 'revoked'))",
        );
        DB::statement(
            'ALTER TABLE scenario_rules ADD CONSTRAINT scenario_rules_delay_check '
            ."CHECK (delay_unit IN ('minutes', 'hours', 'days') AND purpose IN ('service', 'transactional') AND version > 0)",
        );
        DB::statement(
            'ALTER TABLE scenario_events ADD CONSTRAINT scenario_events_status_check '
            ."CHECK (status IN ('pending', 'processing', 'processed', 'retryable', 'failed'))",
        );
        DB::statement(
            'ALTER TABLE scenario_actions ADD CONSTRAINT scenario_actions_status_check '
            ."CHECK (status IN ('scheduled', 'processing', 'delivered', 'retryable', 'failed', 'suppressed', 'cancelled') AND "
            ."((recipient_type = 'client' AND client_id IS NOT NULL AND recipient_user_id IS NULL) OR "
            ."(recipient_type = 'internal' AND client_id IS NULL AND recipient_user_id IS NOT NULL)))",
        );
        DB::statement(
            'ALTER TABLE scenario_deliveries ADD CONSTRAINT scenario_deliveries_status_check '
            ."CHECK (status IN ('pending', 'processing', 'delivered', 'retryable', 'permanent_failure', 'unavailable', 'suppressed'))",
        );
        DB::statement(
            'ALTER TABLE scenario_delivery_attempts ADD CONSTRAINT scenario_delivery_attempts_outcome_check '
            ."CHECK (outcome IN ('delivered', 'retryable', 'permanent_failure', 'unavailable', 'suppressed', 'unknown'))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('scenario_delivery_attempts');
        Schema::dropIfExists('scenario_deliveries');
        Schema::dropIfExists('scenario_actions');
        Schema::dropIfExists('scenario_events');
        Schema::dropIfExists('scenario_rules');
        Schema::dropIfExists('organization_channel_identities');
        Schema::dropIfExists('notification_template_versions');
        Schema::dropIfExists('notification_templates');
    }
};
